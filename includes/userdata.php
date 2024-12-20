<?php


if (!defined('ABSPATH')) {
    exit;
}

include plugin_dir_path(__DIR__) . 'gateways/firebase/JWT.php';
include plugin_dir_path(__DIR__) . 'gateways/firebase/BeforeValidException.php';
include plugin_dir_path(__DIR__) . 'gateways/firebase/ExpiredException.php';
include plugin_dir_path(__DIR__) . 'gateways/firebase/SignatureInvalidException.php';

use Firebase\Dig_Firebase\BeforeValidException;
use Firebase\Dig_Firebase\Dig_Firebase;
use Firebase\Dig_Firebase\ExpiredException;
use Firebase\Dig_Firebase\SignatureInvalidException;


// Method to send Get request to url
function dig_doCurl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $data;
}

function getUserFromPhone($phone)
{
    $phone = sanitize_mobile_field_dig($phone);
    if (!is_numeric($phone) || !$phone) {
        return null;
    }

    global $wpdb;


    $phone = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);

    $b = "digits_phone";
    $usermerow = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->usermeta . '
        WHERE meta_value = %s AND meta_key= %s LIMIT 1',
            $phone, $b
        )
    );

    if ($usermerow) {
        $user = get_user_by('id', $usermerow->user_id);
        if (!empty($usermerow->user_id) && !$user) {
            digits_delete_mobile($usermerow->user_id);
            return getUserFromID($phone);
        } else {
            return $user;
        }

    } else if (get_option('dig_mob_ver_chk_fields', 1) == 0) {

        $phone = str_replace("+", "", $phone);
        $b = "billing_phone";
        $usermerow = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $wpdb->usermeta . '
        WHERE meta_value = %s AND meta_key= %s LIMIT 1',
                $phone, $b
            )
        );


        if ($usermerow) {
            return get_user_by('id', $usermerow->user_id);
        } else {
            null;
        }


    } else {
        null;
    }


}


function getUserIDSfromPhone($phone)
{

    if (!current_user_can('edit_shop_orders')) {
        return;
    }

    $phone = sanitize_mobile_field_dig($phone);
    global $wpdb;


    $phone = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);


    $b = "digits_phone";
    $usermerow = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->usermeta . '
        WHERE meta_value LIKE %s AND meta_key = %s',
            '%' . $phone . '%', $b
        )
    );


    if ($usermerow) {

        $ids = array();
        foreach ($usermerow as $user) {
            $id = get_object_vars($user)['user_id'];
            $ids[] = $id;
        }

        return $ids;

    } else {
        null;
    }


}

function OTPexists($countrycode, $phone, $resend = false)
{
    global $wpdb;
    $countrycode = filter_var($countrycode, FILTER_SANITIZE_NUMBER_INT);
    $phone = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
    $table_name = $wpdb->prefix . "digits_mobile_otp";
    $usermerow = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . $table_name . '
        WHERE countrycode = %s AND mobileno= %s',
            $countrycode, $phone
        )
    );
    if ($usermerow) {
        $time = strtotime($usermerow->time);
        $current = strtotime("now");

        $t = 10;
        if ($resend) {
            $t = 20;
        }

        $diff = $current - $time;
        if ($diff > $t || $diff < 0) {
            $wpdb->delete($table_name, array(
                'countrycode' => $countrycode,
                'mobileno' => $phone
            ), array(
                    '%d',
                    '%d'
                )
            );


            return $resend;
        }

        return true;
    } else {
        return false;
    }
}


function dig_verify_firebase($token, $user_phone)
{

    $firebase = get_option('digit_firebase');
    if (is_array($firebase)) {
        $firebase = implode('', $firebase);
    }

    if (empty($token)) {
        return false;
    }
    try {


        $publicKeyURL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';


        $keys = dig_doCurl($publicKeyURL);

        Dig_Firebase::$leeway = 1080000;
        $decoded = Dig_Firebase::decode($token,
            $keys,
            ['RS256']);
    } catch (SignatureInvalidException $e) {
        return false;
    } catch (InvalidArgumentException $e) {
        return false;
    } catch (BeforeValidException $e) {
        return false;
    } catch (ExpiredException $e) {
        return false;
    } catch (Exception $e) {

        return false;
    }

    $decoded = dig_objectToArray($decoded);

    $iss = $decoded['iss'];
    $aud = $decoded['aud'];
    $exp = $decoded['exp'];
    $mob = $decoded['phone_number'];

    $user_phone = dig_fix_cong_phone($user_phone);
    if ($mob != $user_phone || $exp < time() || strpos($firebase, $aud) === false) {
        return false;
    }

    return true;
}

function dig_fix_cong_phone($phone)
{
    if (strpos($phone, "+242") === 0 || strpos($phone, "+225") === 0) {
        $check_zero = substr($phone, 4, 1);
        if ($check_zero != '0') {
            $phone = substr_replace($phone, "0", 4, 0);
        }
    }
    return $phone;
}

function verifyOTP($countrycode, $phone, $otp, $deleteotp)
{

    $is_phone_allowed = dig_is_phone_no_allowed($countrycode . $phone);
    if (!$is_phone_allowed) {
        return false;
    }

    if (dig_gatewayToUse($countrycode) == 13) {
        if (isset($_REQUEST['dig_ftoken']) || isset($_REQUEST['ftoken'])) {
            $token = isset($_REQUEST['dig_ftoken']) ? $_REQUEST['dig_ftoken'] : $_REQUEST['ftoken'];

            if ($token != -1) {
                return dig_verify_firebase($token, $countrycode . $phone);
            }
        }
    }

    if (empty($otp)) {
        return false;
    }


    $countrycode = str_replace("+", "", $countrycode);
    global $wpdb;


    $countrycode = filter_var($countrycode, FILTER_SANITIZE_NUMBER_INT);
    $phone = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
    $otp = md5($otp);
    $table_name = $wpdb->prefix . "digits_mobile_otp";
    $usermerow = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . $table_name . '
        WHERE countrycode = %s AND mobileno= %s AND otp=%s ORDER BY time DESC LIMIT 1',
            $countrycode, $phone, $otp
        )
    );


    if ($usermerow) {

        $time = strtotime($usermerow->time);
        $current = strtotime("now");

        if ($current - $time > 600) {
            $wpdb->delete($table_name, array(
                'countrycode' => $countrycode,
                'mobileno' => $phone
            ), array(
                    '%d',
                    '%s'
                )
            );

            return false;
        }

        if ($deleteotp) {
            $wpdb->delete($table_name, array(
                'countrycode' => $countrycode,
                'mobileno' => $phone
            ), array(
                    '%d',
                    '%s'
                )
            );
        }

        return true;
    } else {
        return false;
    }


}

function getUserFromID($userid)
{

    global $wpdb;

    $phone = $userid;
    $b = "digits_phone";
    $usermerow = $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . $wpdb->base_prefix . 'usermeta
        WHERE user_id = %s AND meta_key= %s',
            $phone, $b
        )
    );

    if ($usermerow) {
        return true;
    } else {
        false;
    }


}


function dig_objectToArray($d)
{
    if (is_object($d)) {
        $d = get_object_vars($d);
    }
    if (is_array($d)) {
        return array_map(__FUNCTION__, $d);
    } else {
        return $d;
    }
}

function dig_removeStringParameter($url, $varname)
{
    $parsedUrl = parse_url($url);
    $query = array();

    if (isset($parsedUrl['query'])) {
        parse_str($parsedUrl['query'], $query);
        unset($query[$varname]);
    }

    $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
    $query = !empty($query) ? '?' . http_build_query($query) : '';

    return '//' . $parsedUrl['host'] . $path . $query;
}


/*
 * DEPRECATED
 * */
function getUserPhoneFromAccountkit($code)
{

// Initialize variables
    $app = get_option('digit_api');
    $app_id = "";
    $secret = "";
    $version = "";
    if ($app !== false) {
        $app_id = $app['appid'];
        $secret = $app['appsecret'];
        if (isset($app['accountkitversion'])) {
            $version = $app['accountkitversion'];
        } else {
            $version = "v1.1";
        }

    }


// Exchange authorization code for access token
    $token_exchange_url = 'https://graph.accountkit.com/' . $version . '/access_token?' .
        'grant_type=authorization_code' .
        '&code=' . $code .
        "&access_token=AA|$app_id|$secret";


    $data = dig_doCurl($token_exchange_url);

    if (empty($data['id'])) {
        return null;
    }

    $user_id = $data['id'];
    $user_access_token = $data['access_token'];
    $refresh_interval = $data['token_refresh_interval_sec'];

//$user_access_token= hash_hmac('sha256', $user_access_token, $secret);
    $appsecret_proof = hash_hmac('sha256', $user_access_token, $secret);

// Get Account Kit information
    $me_endpoint_url = 'https://graph.accountkit.com/' . $version . '/me?' .
        'access_token=' . $user_access_token . '&appsecret_proof=' . $appsecret_proof;


    $data = dig_doCurl($me_endpoint_url);


    if (isset($data['phone'])) {


        $mobinfo = new stdClass();
        $mobinfo->countrycode = '+' . $data['phone']['country_prefix'];
        $mobinfo->nationalNumber = $data['phone']['national_number'];
        $mobinfo->phone = $data['phone']['number'];

        return json_encode($mobinfo);
    } else {
        return null;
    }


}
