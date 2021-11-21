<?php

namespace Drupal\gigya_raas\Helper;

use Drupal;
use Drupal\Core\Database\Database;
use Drupal\gigya\CmsStarterKit\GSApiException;
use Drupal\gigya\CmsStarterKit\user\GigyaUser;
use Drupal\gigya\CmsStarterKit\user\GigyaUserFactory;
use Drupal\gigya\Helper\GigyaHelper;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Exception;
use Gigya\PHP\GSException;
use InvalidArgumentException;

class GigyaRaasHelper {
	private $gigya_helper;

	public static function getSessionConfig($type = 'regular') {
		if ($type == 'remember_me') {
			$session_type = \Drupal::config('gigya_raas.settings')->get('gigya_raas.remember_me_session_type');
			$session_time = \Drupal::config('gigya_raas.settings')->get('gigya_raas.remember_me_session_time');
		}
		else {
			$session_type = \Drupal::config('gigya_raas.settings')->get('gigya_raas.session_type');
			$session_time = \Drupal::config('gigya_raas.settings')->get('gigya_raas.session_time');
		}

		return [
			'type' => $session_type,
			'time' => $session_time,
		];
	}

	public function __construct() {
		$this->gigya_helper = new GigyaHelper();
	}

	/**
	 * Validates and gets Gigya user
	 *
	 * @param $uid
	 * @param $signature
	 * @param $sig_timestamp
	 *
	 * @return bool | GigyaUser
	 */
	public function validateAndFetchRaasUser($uid, $signature, $sig_timestamp) {
		$params = ['environment' => $this->gigya_helper->getEnvString()];

		$auth_mode = Drupal::config('gigya.settings')->get('gigya.gigya_auth_mode') ?? 'user_secret';

		try {
			return ($auth_mode == 'user_rsa')
				? $this->gigya_helper->getGigyaApiHelper()->validateJwtAuth($uid, $signature, NULL, NULL, $params)
				: $this->gigya_helper->getGigyaApiHelper()->validateUid($uid, $signature, $sig_timestamp, NULL, NULL, $params);
		} catch (GSApiException $e) {
			Drupal::logger('gigya')->error("Gigya API call error: @error, Call ID: @callId", array('@callId' => $e->getCallId(), '@error' => $e->getMessage()));
			return false;
		}
		catch (Exception $e) {
			Drupal::logger('gigya')->error("General error validating gigya UID: " . $e->getMessage());
			return false;
		}
	}

	public function getUidByMail($mail) {
		return Drupal::entityQuery('user')
			->condition('mail',  $mail)
			->execute();
	}

	public function getUidByMails($mails) {
		return Drupal::entityQuery('user')
			->condition('mail',  $mails, 'IN')
			->execute();
	}

	/**
	 * @param $uuid
	 *
	 * @return User|false
	 */
	public function getDrupalUidByGigyaUid($uuid) {
		$uuid_field = Drupal::config('gigya_raas.fieldmapping')->get('gigya.uid_mapping');
		if (empty($uuid_field)) {
			$uuid_field = 'uuid';
		}

		if ($uuid_field === 'uuid') {
			return Drupal::service('entity.repository')->loadEntityByUuid('user', $uuid);
		}
		else {
			$ids = Drupal::entityQuery('user')
				->condition($uuid_field, $uuid)
				->execute();
			$users = User::loadMultiple($ids);

			if ($users[0] instanceof User) {
				return $users[0];
			}
		}

		return false;
	}

	public function getUidByName($name) {
		return Drupal::entityQuery('user')
			->condition('name',  Database::getConnection()->escapeLike($name), 'LIKE')
			->execute();
	}

	/**
	 * @param GigyaUser $gigyaUser
	 * @param integer   $uid
	 *
	 * @return bool
	 */
	public function checkEmailsUniqueness($gigyaUser, $uid) {
		if ($this->checkProfileEmail($gigyaUser->getProfile()->getEmail(), $gigyaUser->getLoginIDs()['emails'])) {
			$uid_check = $this->getUidByMail($gigyaUser->getProfile()->getEmail());
			if (empty($uid_check) || isset($uid_check[$uid])) {
				return $gigyaUser->getProfile()->getEmail();
			}
		}

		foreach ($gigyaUser->getloginIDs()['emails'] as $id) {
			$uid_check = $this->getUidByMail($id);
			if (empty($uid_check) || isset($uid_check[$uid])) {
				return $id;
			}
		}

		return FALSE;
	}

	public function checkProfileEmail($profile_email, $loginIds) {
		$exists = FALSE;
		foreach ($loginIds as $id) {
			if ($id == $profile_email) {
				$exists = TRUE;
			}
		}
		return $exists;
	}

	/**
	 * @return object|null
	 */
	public function getFieldMappingConfig() {
		$config = json_decode(Drupal::config('gigya_raas.fieldmapping')
			->get('gigya.fieldmapping_config'));
		if (empty($config)) {
			$config = (object)Drupal::config('gigya.global')->get('gigya.fieldMapping');
		}

		return $config;
	}

	/**
	 * This function enriches the Drupal user with Gigya data, but it does not permanently save the user data!
	 *
	 * @param GigyaUser     $gigya_data
	 * @param UserInterface $drupal_user
	 */
	public function processFieldMapping(GigyaUser $gigya_data, UserInterface $drupal_user) {
		try {
			$field_map = $this->getFieldMappingConfig();

			try {
				Drupal::moduleHandler()
					->alter('gigya_raas_map_data', $gigya_data, $drupal_user, $field_map);
			}
			catch (Exception $e) {
				Drupal::logger('gigya_raas')->debug('Error altering field map data: @message',
					array('@message' => $e->getMessage()));
			}

			if (!is_object($field_map)) {
				Drupal::logger('gigya_raas')
					->error('Error processing field map data: incorrect format entered. The format for field mapping is a JSON object of the form: &#123;"drupalField": "gigyaField"&#125;. Proceeding with default field mapping configuration.');
				$field_map = json_decode('{}');
			}
			$field_map->uuid = 'UID';
			if (!empty($uid_mapping = Drupal::config('gigya_raas.fieldmapping')->get('gigya_uid_mapping'))) {
				if ($drupal_user->hasField($uid_mapping)) {
          $field_map->uuid = $uid_mapping;
				}
			}

			foreach ($field_map as $drupal_field => $raas_field) {
				/* Drupal fields to exclude even if configured in field mapping schema */
				if ($drupal_field == 'mail' or $drupal_field == 'name') {
					continue;
				}

				/* Field names must be strings. This protects against basic incorrect formatting, though care should be taken */
				if (gettype($drupal_field) !== 'string' or gettype($raas_field) !== 'string') {
					continue;
				}

				$raas_field_parts = explode('.', $raas_field);
				$val = $this->gigya_helper->getNestedValue($gigya_data, $raas_field_parts);

				if ($val !== NULL) {
					$drupal_field_type = 'string';

					try {
						$drupal_field_type = $drupal_user->get($drupal_field)->getFieldDefinition()->getType();
					}
					catch (Exception $e)
					{
						Drupal::logger('gigya')->debug('Error getting field definition for field map: @message',
							['@message' => $e->getMessage()]);
					}

					/* Handles Boolean types */
					if ($drupal_field_type == 'boolean') {
						if (is_bool($val)) {
							$val = intval($val);
						}
						else {
							Drupal::logger('gigya_raas')->error('Failed to map ' . $drupal_field . ' from Gigya - Drupal type is boolean but Gigya type isn\'t');
						}
					}

					/* Perform the mapping from Gigya to Drupal */
					try {
						$drupal_user->set($drupal_field, $val);
					} catch (InvalidArgumentException $e) {
						Drupal::logger('gigya_raas')
							->debug('Error inserting mapped field: @message',
								['@message' => $e->getMessage()]);
					}
				}
			}
		} catch (Exception $e) {
			Drupal::logger('gigya_raas')->debug('processFieldMapping error @message',
				['@message' => $e->getMessage()]);
		}
	}

	/**
	 * Queries Gigya with the accounts.search call
	 *
	 * @param string|array $query The literal query to send to accounts.search,
	 *   or a set of params to send instead (useful for cursors)
	 * @param bool $use_cursor
	 *
	 * @return GigyaUser[]
	 *
	 * @throws GSApiException
	 * @throws GSException
	 */
	public function searchGigyaUsers($query, $use_cursor = FALSE) {
		$api_helper = $this->gigya_helper->getGigyaApiHelper();

		return $api_helper->searchGigyaUsers($query, $use_cursor);
	}

	public function getGigyaUserFromArray($data) {
		return GigyaUserFactory::createGigyaProfileFromArray($data);
	}

	public function sendCronEmail($job_type, $job_status, $to, $processed_items = NULL, $failed_items = NULL, $custom_email_body = '') {
		$email_body = $custom_email_body;
		if ($job_status == 'succeeded' or $job_status == 'completed with errors') {
			$email_body = 'Job ' . $job_status . ' on ' . gmdate("F n, Y H:i:s") . ' (UTC).';
			if ($processed_items !== NULL) {
				$email_body .= PHP_EOL . $processed_items . ' ' . (($processed_items > 1) ? 'items' : 'item') . ' successfully processed, ' . $failed_items . ' failed.';
			}
		}
		elseif ($job_status == 'failed') {
			$email_body = 'Job failed. No items were processed. Please consult the Drupal log (Administration > Reports > Recent log messages) for more info.';
		}

		return $this->gigya_helper->sendEmail('Gigya cron job of type ' . $job_type . ' ' . $job_status . ' on website ' . Drupal::request()
				->getHost(),
			$email_body,
			$to);
	}

  /**
   * @return array that contain error code key and error case key
   */

  public function validateUBCCookie() {

    $compare_option_results = [
      0 => ['errorCode' => 0, 'errorMessage' => "Valid session"],
      1 => ['errorCode' => 1, 'errorMessage' => "There was an error validating the session, the gubc cookie didn't exist. This session will validate via Gigya"],
      2 => ['errorCode' => 2, 'errorMessage' => "There was an error validating the session, the glt cookie didn't exist. This session is closing"],
      3 => ['errorCode' => 3, 'errorMessage' => "The gubc cookie and the glt cookie were empty"],
      4 => ['errorCode' => 4, 'errorMessage' => "There was an error validating the session, the gubc cookie wasn't compatible with glt cookie. This session is closing"],
    ];
    $result = $compare_option_results[0];
    $current_user = Drupal::currentUser();

    if ('until_browser_close' === Drupal::config('gigya_raas.settings')
        ->get('gigya_raas.session_type') && $current_user->isAuthenticated() && !$current_user->hasPermission('bypass gigya raas')) {


      $gigya_conf = Drupal::config('gigya.settings');
      $api_key = $gigya_conf->get('gigya.gigya_api_key');
      $gigya_ubc_cookie = Drupal::request()->cookies->get('gubc_' . $api_key);
      $glt_cookie = Drupal::request()->cookies->get('glt_' . $api_key);

      /*Do if there is glt cookie*/
      if (!empty($glt_cookie)) {
        $glt_token = explode('|', $glt_cookie)[0];

        /*Do if there is ubc cookie*/
        if (!empty($gigya_ubc_cookie)) {
          $gubc_token = explode('|', $gigya_ubc_cookie)[0];

          /*if both  cookies got the same value, the session is valid. otherwise, there was something malicious so do logout*/
          if (!empty($glt_token) and !empty($gubc_token)) {

            if ($glt_token !== $gubc_token) {

              user_logout();
              $result = $compare_option_results[4];
              Drupal::logger("gigya_raas")->debug($result['errorMessage']);
            }
            /*Do while at least one of the cookie is empty*/
          }else {
            user_logout();
            $result = $compare_option_results[4];
            Drupal::logger("gigya_raas")->debug($result['errorMessage']);
          }
          /*Do if there is no ubc cookie*/
        }else {
            $result = $compare_option_results[1];
            Drupal::logger("gigya_raas")->debug($result['errorMessage']);
          }
        /*do if glt cookie is empty*/
      }else {
          if (empty($gigya_ubc_cookie)) {

            $result = $compare_option_results[3];
          }
          else {
            $result = $compare_option_results[2];
          }
          //In any case that user doesn't have 'glt' cookie he will logged out automatically.
          user_logout();
      }
    }

    return $result;
  }

}
