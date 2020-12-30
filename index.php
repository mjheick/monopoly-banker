<?php
/**
 * monopoly-banker
 * A way for people to track monopoly money and properties on a mobile phone using responsive web development
 */

/**
 * Where we store things. Make sure we have it.
 */
$apcuAvailabe = function_exists('apcu_enabled') && apcu_enabled();
if (!$apcuAvailabe)
{
	echo "Need APCu installed";
	die();
}

/**
 * We need a cookie that determines whether we're in a game or not
 */
$monopoly = isset($_COOKIE['monopoly']) ? $_COOKIE['monopoly'] : null;
if (!is_null($monopoly))
{    
	/**
	 * Determine if we've got a "name" and a "game", and see if the "game" is valid
	 * "session"-check
	 */
	$monopoly = base64_decode($monopoly);
	if (strpos($monopoly, ' ') === false)
	{
		setcookie('monopoly', '', 1);
		header("Location: .?error=1", 302);
		die();
	}
	list($name, $game) = explode(' ', $monopoly);
	$gamedata = apcu_fetch($game, $cache);
	if ($cache === false)
	{
		setcookie('monopoly', '', 1);
		header("Location: .?error=2", 302);
		die();
	}
}

/**
 * No cookie, no game. Lets display a page where they can
 * pick a name and a "game", and we can get some data going on
 */
if (is_null($monopoly))
{
	
	die();
}