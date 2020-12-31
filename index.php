<?php
/**
 * monopoly-banker
 * A way for people to track monopoly money and properties on a mobile phone using responsive web development
 */

// Where we store things. Make sure we have it.
$apcuAvailabe = function_exists('apcu_enabled') && apcu_enabled();
if (!$apcuAvailabe)
{
	echo 'Need APCu installed';
	die();
}

// debugging
if (isset($_GET['clear']))
{
	list($name, $game) = explode(' ', base64_decode($_COOKIE['monopoly']));
	apcu_delete($game);
	setcookie('monopoly', '', 1);
	header('Location: ' . $_SERVER['PHP_SELF'] . '?cache=cleared', 302);
	die();
}

// POST data from "Start/Join" screen, which is used to set up a cookie and start/join a session
if (array_key_exists('game_name', $_POST) && array_key_exists('user_name', $_POST))
{
	$game_name = $_POST['game_name'];
	$user_name = $_POST['user_name'];
	// basic parameter validation
	if ((strlen($game_name) == 0) || (strlen($user_name) == 0))
	{
		header('Location: ' . $_SERVER['PHP_SELF'] . '?error=game-or-name-empty', 302);
		die();		
	}
	// See if a game exists
	$gamedata = apcu_fetch($game_name, $cache);
	if ($cache === false)
	{
		// new game
		$game = [
			'state' => 'lobby',
			'players' => [
				$user_name => 1500,
			],
			'banker' => $user_name,
		];
	}
	else
	{
		// check if current game is in lobby so we can add person
		$game = unserialize($gamedata);
		if ($game['state'] != 'lobby')
		{
			header('Location: ' . $_SERVER['PHP_SELF'] . '?error=game-in-progress', 302);
			die();
		}
		// see if this user already exists in the game
		$players = $game['players'];
		if (array_key_exists($user_name, $players))
		{
			header('Location: ' . $_SERVER['PHP_SELF'] . '?error=player-already-in-game', 302);
			die();
		}
		// add player to the game
		$game['players'][$user_name] = 1500;
	}
	// add this game to the store
	$game = serialize($game);
	apcu_store($game_name, $game, 86400);
	// send them a user/game cookie
	setcookie('monopoly', base64_encode($user_name . ' ' . $game_name), time() + 86400);
	// redirect them to the lobby
	header('Location: ' . $_SERVER['PHP_SELF'], 302);
	die();
}

// We need a cookie that determines whether we're in a game or not
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
		header('Location: ' . $_SERVER['PHP_SELF'] . '?error=token-not-proper', 302);
		die();
	}
	list($user_name, $game_name) = explode(' ', $monopoly);
	$game = apcu_fetch($game_name, $cache);
	if ($cache === false)
	{
		setcookie('monopoly', '', 1);
		header('Location: ' . $_SERVER['PHP_SELF'] . '?error=game-not-exist-in-cache', 302);
		die();
	}
	// check if this user exists in this game
	$game_data = unserialize($game);
}

/**
 * POST action= is just our general game loop. We use it to do everything
 * At this point we trust $user_name, $game_name and $game_data
 * All return data is json_encode so java can parse it out
 */
if (array_key_exists('action', $_POST))
{
	$action = $_POST['action'];
	$output = null;
	switch ($action)
	{
		case "getGameState":
			$output = [
				'gamestate' => $game_data['state']
			];
			break;
		default:
			$output = [
				'nothing' => 'null'
			];
			break;
	}
	echo json_encode($output);
	die();
}

/**
 * No cookie, no game. Lets display a page where they can
 * pick a name and a "game", and we can get some data going on
 */
if (is_null($monopoly))
{
?><!DOCTYPE html>
<html>
	<head>
		<title>Monopoly Banker</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
	</head>
	<body>
	<div class="header">Monopoly Banker</div>
	<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
	<div>Game Name: <input type="text" name="game_name" value="monopoly1" /></div>
	<div>Your Name: <input type="text" name="user_name" value="" /></div>
	<div><input type="submit" name="start_button" value="Start/Join" /></div>
	</form>
<?php
if (array_key_exists('error', $_GET))
{
	echo '<div>Error: ' . $_GET['error'] . '</div>'; 
}
?>
	</body>
</html>
<?php
	die();
}

/**
 * This is the main game.
 * Depending on where we are, we display whatever we got
 */
?><!DOCTYPE html>
<html>
	<head>
		<title>Monopoly Banker</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<script>
// A whole clusterf'k of functions that runs this little thing
function getAjax()
{
	var xhr = false;
	try {
		xhr = new XMLHttpRequest();
	} catch (e)	{
		alert(":(");
	}
	return xhr;
}
// asks what state our game is in. Makes the screen active based on that
function getGameState()
{
	var w = getAjax();
	if (w === false) { return; }
	w.onreadystatechange = function() {
		if ((w.readyState == 4) && (w.status == 200)) {
			try {
				var data = JSON.parse(w.responseText);
				if (data.gamestate == 'lobby')
				{
					document.getElementById('lobby').style.display = 'block';
					document.getElementById('game').style.display = 'none';
					getLobbyData();
				}
				if (data.gamestate == 'game')
				{
					document.getElementById('game').style.display = 'block';
					document.getElementById('lobby').style.display = 'none';
					getGameData();
				}
				setTimeout(getGameState, 1000);
			} catch (e) {
				console.log(e);
			}
		}
	};
	var post_data = "action=" + encodeURIComponent("getGameState");
	w.open("POST", "<?php echo $_SERVER["PHP_SELF"]; ?>", true);
	w.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	w.send(post_data);
}
// This gets the data for the Lobby while the game is starting or paused
function getLobbyData()
{
	return;
}
// This gets the data for the Game
function getGameData()
{
	return;
}
// fire up our fun
window.onload = getGameState;
		</script>
		<style>
#lobby {
	display: none;
}
#game {
	display: none;
}
		</style>
	</head>
	<body>
	<div><a href="?clear=game">-clear game-</a></div>
	<div><pre>
<?php
print_r($game_data);
?>
	</pre></div>
	<div id="lobby">
		<div>You are in the Lobby</div>
	</div>
	<div id="game">
		<div>You are playing Monopoly!</div>
	</div>
	</body>
</html>