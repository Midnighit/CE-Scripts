<?php
require __DIR__ . '/vendor/autoload.php';

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function G_getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('CE-Info');
    $client->setScopes(Google_Service_Sheets::SPREADSHEETS);
    $client->setAuthConfig('client_secret.json');
    $client->setAccessType('offline');

    // Load previously authorized credentials from a file.
    $credentialsPath = expandHomeDirectory('credentials.json');
    if (file_exists($credentialsPath)) {
        $accessToken = json_decode(file_get_contents($credentialsPath), true);
    } else {
        // Request authorization from the user.
        $authUrl = $client->createAuthUrl();
        printf("Open the following link in your browser:\n%s\n", $authUrl);
        print 'Enter verification code: ';
        $authCode = trim(fgets(STDIN));

        // Exchange authorization code for an access token.
        $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

        // Store the credentials to disk.
        if (!file_exists(dirname($credentialsPath))) {
            mkdir(dirname($credentialsPath), 0700, true);
        }
        file_put_contents($credentialsPath, json_encode($accessToken));
        printf("Credentials saved to %s\n", $credentialsPath);
    }
    $client->setAccessToken($accessToken);

    // Refresh the token if it's expired.
	if ($client->isAccessTokenExpired()) {
		$oldAccessToken=$client->getAccessToken();
		$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
		$accessToken=$client->getAccessToken();
		$accessToken['refresh_token']=$oldAccessToken['refresh_token'];
		file_put_contents($credentialsPath, json_encode($accessToken));
	}
    return $client;
}

/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path)
{
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory)) {
        $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }
    return str_replace('~', realpath($homeDirectory), $path);
}

#######################################################################################
# START OF SPREADSHEET REQUEST FUNCTIONS
#######################################################################################

/**
 * Sets an basic filter spanning between the upper left and lower right cell given.
 * @param column and row index as they are read on the spreadsheet (e.g. A1 = 1 and 1).
 */
function G_setFilterRequest($sheetId, &$requests, $startColumnIndex = 0, $startRowIndex = 0, $endColumnIndex, $endRowIndex)
{
	$requests[] = new Google_Service_Sheets_Request(
	[
		'setBasicFilter' =>
		[
			'filter' =>
			[
				'range' =>
				[
					'sheetId' => $sheetId,
					'startColumnIndex' => $startColumnIndex-1,
					'endColumnIndex' => $endColumnIndex,
					'startRowIndex' => $startRowIndex-1,
					'endRowIndex' => $endRowIndex
				]
			]
		]
	]);
}

/**
 * Sets the size of the sheet to the given range and allows to freez a number of rows.
 * @param column and row index as they are read on the spreadsheet (e.g. A1 = 1 and 1)
 *        and the number of rows to be frozen.
 */
function G_setGridSize($sheetId, &$requests, $columnCount, $rowCount, $frozenRowCount = 0, $hideGridlines = false)
{
	$requests[] = new Google_Service_Sheets_Request(
	[
		'updateSheetProperties' =>
		[
			'fields' =>	'gridProperties',
			'properties' =>
			[
				'sheetId' => $sheetId,
				'gridProperties' =>
				[
					'columnCount' => $columnCount,
					'rowCount' => $rowCount,
					'frozenRowCount' => $frozenRowCount,
					'hideGridlines' => $hideGridlines
				]
			]
		]
	]);
}

/**
 * Sets a name for the given range.
 * @param column and row index as they are read on the spreadsheet (e.g. A1 = 1 and 1)
 */
function G_addNamedRange($sheetId, &$requests, $name, $namedRangeId = NULL, $startColumnIndex = NULL, $startRowIndex = NULL, $endColumnIndex = NULL, $endRowIndex = NULL)
{
	$request['addNamedRange']['namedRange']['name'] = $name;
	If($namedRangeId) $request['addNamedRange']['namedRange']['namedRangeId'] = (string)$namedRangeId;
	$request['addNamedRange']['namedRange']['range']['sheetId'] = $sheetId;
	if($startColumnIndex) $request['addNamedRange']['namedRange']['range']['startColumnIndex'] = $startColumnIndex - 1;
	if($startRowIndex) $request['addNamedRange']['namedRange']['range']['startRowIndex'] = $startRowIndex - 1;
	if($endColumnIndex) $request['addNamedRange']['namedRange']['range']['endColumnIndex'] = $endColumnIndex;
	if($endRowIndex) $request['addNamedRange']['namedRange']['range']['endRowIndex'] = $endRowIndex;
	$requests[] = new Google_Service_Sheets_Request($request);
}

/**
 * Removes a name.
 * @param namedRangeId of the namedRange to be deleted
 */
function G_deleteNamedRange($sheetId, &$requests, $namedRangeId)
{
	$request['deleteNamedRange']['namedRangeId'] = $namedRangeId;
	$requests[] = new Google_Service_Sheets_Request($request);
}

/**
 * Merge cells within the given range.
 * @param column and row index as they are read on the spreadsheet (e.g. A1 = 1 and 1)
 */
function G_mergeCells($sheetId, &$requests, $startColumnIndex, $startRowIndex, $endColumnIndex = NULL, $endRowIndex = NULL)
{
	if(!$endColumnIndex || ($endColumnIndex < $startColumnIndex)) $endColumnIndex = $startColumnIndex;
	if(!$endRowIndex || ($endRowIndex < $startRowIndex)) $endRowIndex = $startRowIndex;
	$requests[] = new Google_Service_Sheets_Request(
	[
		'mergeCells' =>
		[
			'mergeType' =>	'MERGE_ROWS',
			'range' =>
			[
				'sheetId' => $sheetId,
				'startRowIndex' => $startRowIndex-1,
				'startColumnIndex' => $startColumnIndex-1,
				'endColumnIndex' => $endColumnIndex,
				'endRowIndex' => $endRowIndex
			]
		]
	]);
}

/**
 * Unmerge cells within the given range.
 * @param column and row index as they are read on the spreadsheet (e.g. A1 = 1 and 1)
 */
function G_unmergeCells($sheetId, &$requests, $startColumnIndex, $startRowIndex, $endColumnIndex = NULL, $endRowIndex = NULL)
{
	if(!$endColumnIndex || ($endColumnIndex < $startColumnIndex)) $endColumnIndex = $startColumnIndex;
	if(!$endRowIndex || ($endRowIndex < $startRowIndex)) $endRowIndex = $startRowIndex;
	$requests[] = new Google_Service_Sheets_Request(
	[
		'unmergeCells' =>
		[
			'range' =>
			[
				'sheetId' => $sheetId,
				'startRowIndex' => $startRowIndex-1,
				'startColumnIndex' => $startColumnIndex-1,
				'endColumnIndex' => $endColumnIndex,
				'endRowIndex' => $endRowIndex
			]
		]
	]);
}

/**
 * change the horizontal alignment and/or the data format.
 * @param index range, alignment, format and pattern for the format
 */
function G_changeFormat($sheetId, &$requests, $startColumnIndex, $startRowIndex, $endColumnIndex = NULL, $endRowIndex = NULL, $horizontalAlignment = NULL, $type = NULL, $pattern = NULL)
{
	$request['repeatCell']['fields'] = 'userEnteredFormat';
	$request['repeatCell']['range']['sheetId'] = $sheetId;
	$request['repeatCell']['range']['startColumnIndex'] = $startColumnIndex - 1;
	$request['repeatCell']['range']['startRowIndex'] = $startRowIndex - 1;
	if($endColumnIndex) $request['repeatCell']['range']['endColumnIndex'] = $endColumnIndex;
	if($endRowIndex) $request['repeatCell']['range']['endRowIndex'] = $endRowIndex;
	if($horizontalAlignment) $request['repeatCell']['cell']['userEnteredFormat']['horizontalAlignment'] = $horizontalAlignment;
	if($type) $request['repeatCell']['cell']['userEnteredFormat']['numberFormat']['type'] = $type;
	if($pattern) $request['repeatCell']['cell']['userEnteredFormat']['numberFormat']['pattern'] = $pattern;
	$requests[] = new Google_Service_Sheets_Request($request);
}

/**
 * remove all groups within the given range and dimension.
 * @param start and end index and the dimension (ROWS or COLUMNS) for the indices
 */
function G_deleteGroup($sheetId, &$requests, $startIndex, $endIndex, $dimension = 'ROWS')
{
	$request['deleteDimensionGroup']['range']['sheetId'] = $sheetId;
	$request['deleteDimensionGroup']['range']['startIndex'] = $startIndex - 1;
	$request['deleteDimensionGroup']['range']['endIndex'] = $endIndex;
	$request['deleteDimensionGroup']['range']['dimension'] = $dimension;
	$requests[] = new Google_Service_Sheets_Request($request);
}

function G_unhideCells($sheetId, &$requests, $startIndex, $endIndex, $dimension = 'ROWS')
{
	$request['updateDimensionProperties']['fields'] = 'hiddenByUser';
	$request['updateDimensionProperties']['range']['sheetId'] = $sheetId;
	$request['updateDimensionProperties']['range']['startIndex'] = $startIndex - 1;
	$request['updateDimensionProperties']['range']['endIndex'] = $endIndex;
	$request['updateDimensionProperties']['range']['dimension'] = $dimension;
	$request['updateDimensionProperties']['properties']['hiddenByUser'] = false;
	$requests[] = new Google_Service_Sheets_Request($request);
}

function G_hideCells($sheetId, &$requests, $startIndex, $endIndex, $dimension = 'ROWS')
{
	$request['updateDimensionProperties']['fields'] = 'hiddenByUser';
	$request['updateDimensionProperties']['range']['sheetId'] = $sheetId;
	$request['updateDimensionProperties']['range']['startIndex'] = $startIndex - 1;
	$request['updateDimensionProperties']['range']['endIndex'] = $endIndex;
	$request['updateDimensionProperties']['range']['dimension'] = $dimension;
	$request['updateDimensionProperties']['properties']['hiddenByUser'] = true;
	$requests[] = new Google_Service_Sheets_Request($request);
}

/**
 * add a group over the given range and dimension.
 * @param start and end index and the dimension (ROWS or COLUMNS) for the indices
 */
function G_addGroup($sheetId, &$requests, $startIndex, $endIndex, $dimension = 'ROWS')
{
	$request['addDimensionGroup']['range']['sheetId'] = $sheetId;
	$request['addDimensionGroup']['range']['startIndex'] = $startIndex - 1;
	$request['addDimensionGroup']['range']['endIndex'] = $endIndex;
	$request['addDimensionGroup']['range']['dimension'] = $dimension;
	$requests[] = new Google_Service_Sheets_Request($request);
}

/**
 * forms groups over the given range and dimension according to a group criteria in a given column
 * @param $array of values with the criteria at column grpCol, start and end index and the dimenion for the indices
 G_addGroupByColumn($sheetId, $requests, $values, 2, $rows['firstData'], $rows['lastData']);
 */
function G_addGroupByColumn($sheetId, &$requests, $values, $grpCol, $startIndex, $endIndex, $hide = true, $dimension = 'ROWS')
{
	$cmp = $row = $startIndex - 1;
	$grp = $values[$row][$grpCol];
	while($row < $endIndex)
	{
		// Compare the next rows group criteria value with this ones. If it differs from the current one, this is the last row of the group.
		if(!isset($values[$row + 1]) || $values[$row + 1][$grpCol] != $grp)
		{
			// If the row differs from the comparison starting row number there have to be at least two rows that can be grouped
			if($row != $cmp && $row >= $startIndex)
			{
				// Grouping starts at the row below the top row
				G_addGroup($sheetId, $requests, $cmp + 2, $row + 1, $dimension);
				if($hide) G_hideCells($sheetId, $requests, $cmp + 2, $row + 1, $dimension);
			}
			// regardless of whether a new group was added or not, the new row numer to compare to is the next row.
			$cmp = $row + 1;
			if(isset($values[$cmp])) $grp = $values[$cmp][$grpCol];
		}
		$row++;
	}
}
?>