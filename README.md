# monopoly-banker
A way to centralize and streamline the cash game of monopoly

# Requirements

PHP
[APCu](https://pecl.php.net/package/APCU)

# How to Use

Access the page and create a game with a user (spaces should be avoided). The first person creating the game is the banker, and every subsequent user joining is simply a player.

Players can only Pay money to other players. That's what Monopoly is all about, right?

The banker can perform the following actions.
- Act as a player, and Pay money to other players.
- Give and remove money from other players, such as in passing go (Adding $200 to a player) or a Chance Card making the player pay.
- Pass "banker" role to another player.
- Kick a player out of the game.
- Start and Pause a game.

# Known Bugs

A player cannot cleanly join if they already exist, and need to due to some external issue. A workaround is that the player would need to be kicked by the banker, then join and then be funded by the banker.

Free Parking, the board-square player's cash would need to be created as a "Player" by someone who is not the banker so that it could be funded/defunded by the banker alone.

# To Do

- Logging per event
- Timestamping the changes so we can only pull data when changed