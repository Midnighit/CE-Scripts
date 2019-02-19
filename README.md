# CE-Scripts
PHP scripts to maintain and create statistics about a CE database

CE_functions.php:
some baseline functions required by several of the other scripts.
SQL-commands.php:
is executed on every server restart and can be used for specific tasks that only need to be done once.
characterlist.php:
uploads a list of all characters on the server to a google sheet.
google_sheets_client.php:
original google sheets client API downloaded from https://developers.google.com/sheets/api/ and some custom formatting functions
inactives.php:
uploads a list of all inactive characters/clans and ruins to a google sheet.
index.html:
access to the various statistics update scripts via webbrowser.
no-owner.php:
uploads a list of all buildings that have no current owner.
processLog.php:
parses the server log for valuable entries and uploads them to a google sheet.
quickStatistics.php:
compiles a statistic about the number of active players on the server every 5 minutes and uploads it to a google sheet.
ruins.php:
creates ruins from buildings of inactive characters.
statistics.php:
compiles comprehensive statistics about buildings, players and activity on the server once every 24h and uploads them to a google sheet.
tilespermember.php:
uploads a list of all owners on the server and the number of building tiles they own to a google sheet.
updateRecipes:
creates a conversion table to calculate relative costs for various items based on their ingredients.
