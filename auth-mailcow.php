<?php
/***
/usr/local/etc/piler/auth-mailcow.php
(C) 2021-2024, Patrik Kernstock - patrik.kernstock.net.
GNU GPL3 License - No warranty. Use at own risk.

## REQUIREMENTS
- working mailpiler installation
  (see supported versions at https://github.com/patschi/mailpiler-mailcow-integration)
- working mailcow installation
- adjusting mailpiler configuration as below

## DESCRIPTION
After logging in with IMAP authentication, this can be used
to retrieve all alias email addresses, including wildcard,
the mailbox/username has access to, including the realname.

Description for config settings:
- MAILCOW_API_KEY: API key to access mailcow API to retrieve info required.
- MAILCOW_SET_REALNAME: On login, set name of piler to mailbox name of mailcow. Default false.
- CUSTOM_EMAIL_QUERY_FUNCTION: Piler feature to get this to work and integrate this.

## USAGE
Add config settings to /usr/local/etc/piler/config-site.php:
```
$config['MAILCOW_API_KEY'] = 'YOUR_READONLY_API_KEY';
$config['MAILCOW_SET_REALNAME'] = true; // default = false
$config['CUSTOM_EMAIL_QUERY_FUNCTION'] = 'query_mailcow_for_email_access';
include('auth-mailcow.php');
```
*/

// query_mailcow_for_email_access($username) is a custom authentication
// function to overwrite the email addresses the user has access to.
function query_mailcow_for_email_access($username = '')
{
	global $config;
	$session = Registry::get('session');
	$data = $session->get('auth_data');

	// Check if $data and $username has any data we can process.
	// This is not the case when using local accounts, e.g. admin@local.
	if ($data === '' || $username === '') {
		return [];
	}

	// get emails where user has access to.
	$emails = mailcow_get_aliases($username);
	$wildcards = [];
	foreach ($emails as $i => $email) {
		// if email starts with "@", it's a domain wildcard alias
		if (substr($email, 0, 1) === '@') {
			$wildcards[$i] = substr($email, 1);
			unset($emails[$i]); // lets be memory efficient
		}
	}
	$data['emails'] = array_merge($data['emails'] , $emails);

	// set realname, if available.
	if (isset($config['MAILCOW_SET_REALNAME'])
		&& $config['MAILCOW_SET_REALNAME'] === true) {
		// get the name from the mailcow API
		$realname = mailcow_get_mailbox_realname($username);
		if ($realname !== null) {
			$data['realname'] = $realname;
		}
	}

	// wildcard_domains support was implemented on 2020-10-31:
	// https://bitbucket.org/jsuto/piler/issues/1102
	// Released in piler 1.3.10.
	$session->set('wildcard_domains', $wildcards);
	$session->set('auth_data', $data);
}

// mailcow_get_aliases($mailbox) returns back the name of the
// mailbox user (specified in the mailbox details)
// Used to display this name on the top-right of mailpiler.
function mailcow_get_mailbox_realname($mailbox = '')
{
	// let's check if mailbox provided
	if ($mailbox !== '') {
		return null;
	}

	// get mailbox info from mailcow instance
	$api = mailcow_query_api(sprintf('v1/get/mailbox/%s', $mailbox));
	if ($api !== null && !empty($api->name)) {
		return trim($api->name);
	}

	return null;
}

// mailcow_get_aliases($mailbox) returns back all aliases of
// given mailbox by querying the mailcow API.
function mailcow_get_aliases($mailbox = '')
{
	// get all aliases from mailcow instance
	$api = mailcow_query_api('v1/get/alias/all');
	if ($api === null) {
		// API returned something wrong
		return [];
	}

	// build an array of active aliases
	$active_aliases = [];
	foreach ($api as $alias) {
		// skip inactive aliases, new and old instance api fields
		if ((isset($alias->active_int) && $alias->active_int !== 1) ||
		    (isset($alias->active) && $alias->active !== 1)) {
	 		continue;
		}
		$active_aliases[] = [
			'address' => trim(strtolower($alias->address)),
			'goto' => array_map('trim', array_map('strtolower', explode(',', $alias->goto)))
		];
	}

	// recursively find all aliases that route to the mailbox
	$find_routing_aliases = function($target, $seen = []) use (&$find_routing_aliases, $active_aliases) {
		$result = [];

		foreach ($active_aliases as $alias) {
			// skip if we've already processed this alias (prevent loops)
			if (in_array($alias['address'], $seen)) {
				continue;
			}

			// check if alias routes to target
			if (in_array($target, $alias['goto'])) {
				$result[] = $alias['address'];
				// recursively find aliases that route to this specific one
				$result = array_merge(
					$result,
					$find_routing_aliases($alias['address'], array_merge($seen, [$alias['address']]))
				);
			}
		}

		return array_unique($result);
	};

	// find all aliases that route to our mailbox
	$mailbox = strtolower($mailbox);
	$emails = $find_routing_aliases($mailbox);

	// add wildcard aliases (@domain.tld)
	foreach ($active_aliases as $alias) {
		if (substr($alias['address'], 0, 1) === '@') {
			// For wildcards, check if any goto matches our target mailbox
			if (in_array($mailbox, $alias['goto'])) {
				$emails[] = $alias['address'];
			}
		}
	}

	return array_unique($emails);
}

// mailcow_query_api($path) queries the mailcow API and returns
// back object with data or null when error occurred.
// In short: Basic API query function.
function mailcow_query_api($path)
{
	global $config;

	// set hostname to use
	$host = $config['IMAP_HOST'];
	// if MAILCOW_HOST set, overwrite it
	if (isset($config['MAILCOW_HOST'])) {
		$host = $config['MAILCOW_HOST'];
	}

	// get data from mailcow instance
	$api = file_get_contents(
		sprintf('https://%s/api/%s', $host, $path),
		false, 
		stream_context_create([
			'http' => [
				'method' => 'GET',
				'header' => [
					'Accept: application/json',
					sprintf('X-API-Key: %s', $config['MAILCOW_API_KEY'])
				],
				'timeout' => 5, // 5 secs timeout.
			]
		]));

	// decode json
	$api = json_decode($api);
	// check if we got valid json.
	if (json_last_error() === JSON_ERROR_NONE) {
		return $api;
	}
	return null;
}
