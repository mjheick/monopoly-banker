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
			'lastmod' => time(),
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
		$game['lastmod'] = time();
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

// Only GET needed during the game
if (isset($_GET['action']) && $_GET['action'] == 'logout')
{
	setcookie('monopoly', '', 1);
	header('Location: ' . $_SERVER['PHP_SELF'], 302);
	die();
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
	// CHA CHING
	if (substr($action, 0, 10) == 'payPlayer:')
	{
		$player_to_pay = substr($action, 10);
		$value = $_POST['value'];
		// see if player has the cash
		$cash = 0;
		foreach ($game_data['players'] as $player => $money)
		{
			if ($player == $user_name)
			{
				$cash = $money;
			}
		}
		// player can make such a payment
		if ($cash > $value)
		{
			$game_data['players'][$player_to_pay] = $game_data['players'][$player_to_pay] + $value;
			$game_data['players'][$user_name] = $game_data['players'][$user_name] - $value;
			apcu_store($game_name, serialize($game_data), 86400);
		}
	}
	switch ($action)
	{
		case "getGameState":
			$output = [
				'lastmod' => $game_data['lastmod'],
				'gamestate' => $game_data['state']
			];
			break;
		case "getPlayerData":
			$p = [];
			foreach ($game_data['players'] as $player => $money)
			{
				$p[] = $player;
			}
			$output = [
				'lastmod' => $game_data['lastmod'],
				'players' => $p,
				'banker' => $game_data['banker'],
			];
			if ($game_data['banker'] == $user_name)
			{
				$output['admin'] = 'true'; /* Yes, you are the banker */
			}
			break;
		case "getGameData":
			$cash = 0;
			foreach ($game_data['players'] as $player => $money)
			{
				if ($user_name == $player)
				{
					$cash = $money;
				}
			}
			$output = [
				'lastmod' => $game_data['lastmod'],
				'players' => $game_data['players'],
				'banker' => $game_data['banker'],
				'user' => $user_name,
				'money' => $cash,
			];
			if ($game_data['banker'] == $user_name)
			{
				$output['admin'] = 'true'; /* Yes, you are the banker */
			}
			break;
		case "banker":
			if ($game_data['banker'] != $user_name) { die(); }
			$bankerAction = $_POST['bankerAction'];
			$bankerValue = $_POST['bankerValue'];
			if (substr($bankerAction, 0, 8) == 'addCash:')
			{
				$player_to_pay = substr($bankerAction, 8);
				$game_data['players'][$player_to_pay] = $game_data['players'][$player_to_pay] + $bankerValue;
			}
			if (substr($bankerAction, 0, 8) == 'subCash:')
			{
				$player_to_pay = substr($bankerAction, 8);
				$game_data['players'][$player_to_pay] = $game_data['players'][$player_to_pay] - $bankerValue;
			}
			if ($bankerAction == 'kickPlayer')
			{
				// note, make sure we can't kick the banker
				unset($game_data['players'][$bankerValue]);
			}
			if ($bankerAction == 'newBanker')
			{
				$game_data['banker'] = $bankerValue;
			}
			if ($bankerAction == 'setGameState')
			{
				$game_data['state'] = $bankerValue;
			}
			apcu_store($game_name, serialize($game_data), 86400);
			// banker actions require no return value, since it will end up showing up everywhere
			die();
			break; // customary break
		default:
			$output = [
				'lastmod' => $game_data['lastmod'],
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
<html lang="en">
	<head>
		<title>Monopoly Banker</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<!-- Latest compiled and minified CSS -->
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
		<!-- Optional theme -->
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">
	</head>
	<body>
	<div class="container">
		<form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
		<div class="row">
			<div class="col-xs-12">Monopoly Banker</div>
		</div>
		<div class="row">
			<div class="col-xs-4">Game Name:</div>
			<div class="col-xs-8"><input type="text" name="game_name" value="monopoly<?php echo mt_rand(10, 99); ?>" /></div>
		</div>
		<div class="row">
			<div class="col-xs-4">Your Name:</div>
			<div class="col-xs-8"><input type="text" name="user_name" value="derpy<?php echo mt_rand(1000, 9999); ?>" /></div>
		</div>
		<div class="row">
			<div class="col-xs-12"><input type="submit" name="start_button" value="Start/Join" /></div>
		</div>
		</form>
	<?php
	if (array_key_exists('error', $_GET))
	{
		echo '<div class="row"><div class="col-xs-12">Error: ' . $_GET['error'] . '</div></div>'; 
	}
	?>
		<!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
		<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
		<!-- Latest compiled and minified JavaScript -->
		<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
	</div>
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
<html lang="en">
	<head>
		<title>Monopoly Banker</title>
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<!-- Latest compiled and minified CSS -->
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css" integrity="sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u" crossorigin="anonymous">
		<!-- Optional theme -->
		<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css" integrity="sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp" crossorigin="anonymous">
		<script>
// a variable that helps us from pounding data from the server
var lastmod = 0;
// A whole clusterf'k of functions that runs this little thing
function getAjax()
{
	var xhr = false;
	try {
		xhr = new XMLHttpRequest();
	} catch (e)	{
		alert(':(');
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
	var w = getAjax();
	w.onreadystatechange = function() {
		if ((w.readyState == 4) && (w.status == 200)) {
			try {
				var data = JSON.parse(w.responseText);
				var lobbyHTML = '';
				var playerlist = data.players;
				for (let x = 0; x < playerlist.length; x++)
				{
					if (playerlist[x] == data.banker)
					{
						lobbyHTML += '<div>' + playerlist[x] + ' (banker)</div>';
					}
					else
					{
						lobbyHTML += '<div>' + playerlist[x] + '</div>';
					}
				}
				if (data.hasOwnProperty('admin'))
				{
					lobbyHTML += '<div class="row"><div class="col-xs-12"><button onclick="javascript:banker(\'setGameState\', \'game\');">Start the Game</button></div></div>';
				}
				document.getElementById('lobby_data').innerHTML = lobbyHTML;
			} catch (e) {
				console.log(e);
			}
		}
	};
	var post_data = "action=" + encodeURIComponent("getPlayerData");
	w.open("POST", "<?php echo $_SERVER["PHP_SELF"]; ?>", true);
	w.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	w.send(post_data);
}
// This gets the data for the Game
function getGameData()
{
	var w = getAjax();
	w.onreadystatechange = function() {
		if ((w.readyState == 4) && (w.status == 200)) {
			try {
				var data = JSON.parse(w.responseText);
				var gameHTML = '';
				if (data.hasOwnProperty('admin')) {
					gameHTML += '<div class="row"><div class="col-xs-12">You are the Banker!</div></div>';
				}
				gameHTML += '<div class="row"><div class="col-xs-12">You have $' + data.money + '</div></div>';
				gameHTML += '<hr>';
				for (const [key, value] of Object.entries(data.players))
				{
					if (key != data.user) {
						if (key == data.banker)
						{
							gameHTML += '<div class="row">';
							gameHTML += '<div class="col-xs-6">' + key + ' [b]</div>';
							gameHTML += '<div class="col-xs-3">$' + value + '</div>';
							gameHTML += '<div class="col-xs-3"><button onclick="payPlayer(\'' + key + '\', ' + data.money + ');">Pay</button></div>';
							gameHTML += '</div>';
						}
						else
						{
							if (data.hasOwnProperty('admin'))
							{
								gameHTML += '<div class="row">';
								gameHTML += '<div class="col-xs-6">' + key + '</div>';
								gameHTML += '<div class="col-xs-3">$' + value + '</div>';
								gameHTML += '<div class="col-xs-3"><button onclick="payPlayer(\'' + key + '\', ' + data.money + ');">Pay</button></div>';
								gameHTML += '</div>';
								gameHTML += '<div class="row">';
								gameHTML += '<div class="col-xs-3" onclick="bankerKick(\'' + key + '\');"><button>kick</button></div>';
								gameHTML += '<div class="col-xs-3" onclick="makeBanker(\'' + key + '\');"><button>banker</button></div>';
								gameHTML += '<div class="col-xs-3" onclick="bankerAdd(\'' + key + '\');"><button>++</button></div>';
								gameHTML += '<div class="col-xs-3" onclick="bankerSub(\'' + key + '\');"><button>--</button></div>';
								gameHTML += '</div>';
							}
							else
							{
								gameHTML += '<div class="row">';
								gameHTML += '<div class="col-xs-6">' + key + '</div>';
								gameHTML += '<div class="col-xs-3">$' + value + '</div>';
								gameHTML += '<div class="col-xs-3"><button onclick="payPlayer(\'' + key + '\', ' + data.money + ');">Pay</button></div>';
								gameHTML += '</div>';
							}
						}
						gameHTML += '<hr>';
					}
				}
				if (data.hasOwnProperty('admin'))
				{
					gameHTML += '<div class="row"><div class="col-xs-12"><button onclick="javascript:banker(\'setGameState\', \'lobby\');">Pause the Game</button></div></div>';
				}
				document.getElementById('game_data').innerHTML = gameHTML;
			} catch (e) {
				console.log(e);
			}
		}
	};
	var post_data = "action=" + encodeURIComponent("getGameData");
	w.open("POST", "<?php echo $_SERVER["PHP_SELF"]; ?>", true);
	w.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	w.send(post_data);
}
// Basic function of Monopoly: Give your cash away
function payPlayer(who, networth)
{
	var amt = prompt("Give " + who + " how much $$$?", "0");
	if (amt != null)
	{
		if (amt < networth)
		{
			/* Make the call, cause we have something that says we can */
			var w = getAjax();
			w.onreadystatechange = function() {
				if ((w.readyState == 4) && (w.status == 200)) {
					try {
						/* We really don't care about the response. The backend should provide changes to the frontend */
						var data = JSON.parse(w.responseText);
					} catch (e) {
						console.log(e);
					}
				}
			};
			var post_data = "action=" + encodeURIComponent("payPlayer:" + who) + "&value=" + encodeURIComponent(amt);
			w.open("POST", "<?php echo $_SERVER["PHP_SELF"]; ?>", true);
			w.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
			w.send(post_data);
		}
		else
		{
			alert("You tried to pay $" + amt + " when you only got $" + networth);
		}
	}
}
// for banker actions, this encodes data
function banker(action, value)
{
	var w = getAjax();
	w.onreadystatechange = function() {
		if ((w.readyState == 4) && (w.status == 200)) {
			try {
				var data = JSON.parse(w.responseText);
			} catch (e) {
				console.log(e);
			}
		}
	};
	var post_data = "action=" + encodeURIComponent("banker") + "&bankerAction=" + encodeURIComponent(action) + "&bankerValue=" + encodeURIComponent(value);
	w.open("POST", "<?php echo $_SERVER["PHP_SELF"]; ?>", true);
	w.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
	w.send(post_data);
}
function bankerAdd(who)
{
	var amt = prompt("Give " + who + " how much $$$?", "0");
	if (amt != null)
	{
		banker('addCash:' + who, amt);
	}
}
function bankerSub(who)
{
	var amt = prompt("Remove how much $$$ from " + who + "?", "0");
	if (amt != null)
	{
		banker('subCash:' + who, amt);
	}
}
function bankerKick(who)
{
	var welp = confirm("Are you sure you want to kick " + who);
	if (welp)
	{
		banker('kickPlayer', who);
	}
}
function makeBanker(who)
{
	var derp = confirm("Are you sure you want to make " + who + " the banker?");
	if (derp)
	{
		banker('newBanker', who);
	}
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
	<div class="container">
	<div id="lobby">
		<div class="row">
			<div class="col-xs-12">You are in the Lobby</div>
		</div>
		<hr>
		<div id="lobby_data"></div>
	</div>
	<div id="game">
		<div class="row">
			<div class="col-xs-12">You are playing Monopoly!</div>
		</div>
		<hr>
		<div id="game_data"></div>
		<div class="row">
			<div class="col-xs-12"><button onclick="document.location='?action=logout';">Log Out of Game</button></div>
		</div>
<!--
		<div class="row">
			<div class="col-xs-12"><button onclick="document.location='?clear=game';">Clear Game</button></div>
		</div>
-->
	</div>
    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
	<!-- Latest compiled and minified JavaScript -->
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
	</body>
</html>