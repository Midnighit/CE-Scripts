<?php
if(isset($_SERVER['REMOTE_ADDR']))
{
	echo "<pre>";
	$html = true;
	$lb = "<br>";
}
else
{
	$html = false;
	$lb = "\n";
}
$stime = microtime(true);
/******************************************
* Functions, global variables & constants *
******************************************/

// remove all non-alphanumeric characters
function norm($input)
{
	return preg_replace("/([^\w])/", '', $input);
}

// extract recipe name from Recipestable
function getName($input, $normalize = FALSE)
{
	preg_match('/,\s"([^"]+)"\)/', stripcslashes($input), $result);
	if($normalize) norm($result[1]);
	return $result[1];
}

// extract the rowNames from any template string
function getRowNames($input, $normalize = FALSE)
{
	foreach($input as $line)
	preg_match_all('/RowName="(\w+)"/', $input, $result);
	return $result;
}

// returns a list of recipes that have the given itemID as a result
function isProducedBy($itemID)
{
	global $recipes;
	// Check if there is a recipe with the same recipeID as the itemID we're looking for
	if(isset($recipes[$itemID]))
	{
		// go through the results. If a resultID matches the searched itemID, return the recipeID and quantity
		foreach($recipes[$itemID]['ResultIDs'] as $index => $resultID) if($resultID == $itemID)
		{
			$recipeList[] = ['RecipeID' => $itemID, 'Quantity' => $recipes[$itemID]['ResultQuantities'][$index]];
			return $recipeList;
		}
	}
	// If no recipe with matching ID has been found, go through all the recipes that include the itemID as a result
	foreach($recipes as $recipeID => $recipe) foreach($recipe['ResultIDs'] as $index => $resultID) if($resultID == $itemID)
		$recipeList[] = ['RecipeID' => $recipeID, 'Quantity' => $recipe['ResultQuantities'][$index]];
	return $recipeList;
}

// remove identical ingredients and results in a single recipe. If that means a recipe has no more ingredients or results at all, remove the recipe.
function fixCircularity($recipeID)
{
	global $items, $recipes;
	
	// Go through all ingredients of the recipe and see if they're also a result
	foreach($recipes[$recipeID]['IngredientIDs'] as $index => $ingredientID) if(in_array($ingredientID, $recipes[$recipeID]['ResultIDs']))
	{
		// if one is found, unset both ingredient and result
		unset($recipes[$recipeID]['Recipe_Display'][$index]);
		unset($recipes[$recipeID]['Recipe_Calc'][$index]);
		unset($recipes[$recipeID]['Ingredients'][$index]);
		unset($recipes[$recipeID]['IngredientIDs'][$index]);
		unset($recipes[$recipeID]['IngredientQuantities'][$index]);
		unset($recipes[$recipeID]['Product_Display'][$index]);
		unset($recipes[$recipeID]['Results'][$index]);
		unset($recipes[$recipeID]['ResultIDs'][$index]);
		unset($recipes[$recipeID]['ResultQuantities'][$index]);
		// if this means there are either no more ingredients or results, remove the recipe
		if(count($recipes[$recipeID]['IngredientIDs']) == 0 || count($recipes[$recipeID]['ResultIDs']) == 0)
		{
			unset($recipes[$recipeID]);
			return TRUE;
		}
		// otherwise sort all ingredients and results to make sure they all start with the index 0
		else
		{
			sort($recipes[$recipeID]['Ingredients']);
			sort($recipes[$recipeID]['IngredientIDs']);
			sort($recipes[$recipeID]['IngredientQuantities']);
			sort($recipes[$recipeID]['Results']);
			sort($recipes[$recipeID]['ResultIDs']);
			sort($recipes[$recipeID]['ResultQuantities']);
		}
	}
}

// takes an itemID and returns it's actual price and the formula to have it calculated by google sheets
function setPrice($itemID)
{
	global $items, $recipes;
	// if a given item already has a price assigned return the price to the calling function.
	if(isset($items[$itemID]['Price'])) return $items[$itemID]['Price'];
	// if it's not assigned yet the price for the current recipe will have to be determined
	else
	{
		// find either the recipe with the same id or all the recipes that have the desired ingredient as result
		$recipeList = isProducedBy($itemID);
		// go through all the recipes that have the item as a result
		foreach($recipeList as $recipe)
		{
			// set the price to zero
			$price['Actual'] = 0;
			// iterate over all the ingredients of the recipe
			foreach($recipes[$recipe['RecipeID']]['IngredientIDs'] as $index => $ingredientID)
			{
				// get the price for the current ingredient
				$result = setPrice($ingredientID);
				// add its price multiplied by the number of items required
				$price['Actual'] += $recipes[$recipe['RecipeID']]['IngredientQuantities'][$index] * $result['Actual'];
				// store each ingredients item tier
				$price['Tier'][] = $items[$ingredientID]['Tier'];
				// construct the string for the sheet
				$name = norm($recipes[$recipe['RecipeID']]['Ingredients'][$index]) . '_' . $ingredientID;
				$quantity = $recipes[$recipe['RecipeID']]['IngredientQuantities'][$index];
				$calc = ($quantity > 1 ? $quantity . '*' . $name : $name);
				$price['Calc'][$index] = $calc;
			}
			// the tier of the result is one higher than that of its highest tier ingredient
			$priceList['Tier'][$recipe['RecipeID']] = (max($price['Tier'])) + 1;
			$priceList['Actual'][$recipe['RecipeID']] = $price['Actual'] / $recipe['Quantity'];
			$priceList['Calc'][$recipe['RecipeID']] = $price['Calc'];
			$priceList['Quantity'][$recipe['RecipeID']] = $recipe['Quantity'];
			unset($price);
		}
		// get the key for the least expensive recipe
		$key = array_keys($priceList['Actual'], min($priceList['Actual']))[0];
		// assign price and newly calcualted tier to the item
		$items[$itemID]['Tier'] = $priceList['Tier'][$key];
		$items[$itemID]['Price']['Actual'] = $priceList['Actual'][$key];
		$items[$itemID]['Price']['Calc'] = $priceList['Calc'][$key];
		$items[$itemID]['Price']['Quantity'] = $priceList['Quantity'][$key];
		
		// return the price to the calling function
		return $items[$itemID]['Price'];
	}
}

echo $lb . 'Connecting to google... ';

// Get the Google API client and construct the service object
require 'google_sheets_client.php';
$client = G_getClient();
$service = new Google_Service_Sheets($client);
// defining some general constants
define('SPREADSHEET_ID', '18qMc5fT-L8RJqYgG1CBiCpnZ0U7TcOSCiqTc8hVLoHY');
define('RESOURCES_SHEET_ID', '0');
define('ITEMS_SHEET_ID', '335334661');
define('RECIPES_SHEET_ID', '1136815510');
define('PRICES_SHEET_ID', '75361171');

echo 'done!' . $lb;

/*********************
* Table preparations *
*********************/

echo 'Reading items table from ItemTable.json... ';

/// items Table:
// complile all the relevant information into the items table
// Key: ItemId / Value: Array with Name, Tier, Type and Ingredient and Result flag
$json = file_get_contents('./db/ItemTable.json');
$result = json_decode($json, true);
foreach($result as $row => $value)
{
	$unsorted['Name'][] = getName($value['Name']);
	$unsorted['Type'][] = $value['GUICategory'];
	$unsorted['ItemID'][] = $value['RowName'];
	unset($result[$row]);
}
array_multisort($unsorted['Name'], $unsorted['ItemID'], $unsorted['Type']);
foreach($unsorted['ItemID'] as $key => $itemID) $items[$itemID] = ['Row' => $key + 2, 'Name' => $unsorted['Name'][$key], 'Tier' => 0, 'Type' => $unsorted['Type'][$key], 'Price' => NULL, 'isIngredient' => FALSE, 'isResult' => FALSE, 'isResource' => FALSE];
unset($unsorted);
// Add a virtual Water item to allow flasks to be filled.
$items['11504'] = ['Row' => $key + 3, 'Name' => 'Water', 'Tier' => 0, 'Type' => 'Material', 'Price' => NULL, 'isIngredient' => TRUE, 'isResult' => FALSE, 'isResource' => TRUE];

echo 'done!' . $lb;
echo 'Reading translations table from ItemNameToTemplateID.json... ';

// name2id Table:
// read translation table to match item names with their template IDs
// Key: ItemName(db) / Value: ItemId
$json = file_get_contents('./db/ItemNameToTemplateID.json');
$result = json_decode($json, true);
foreach($result as $row => $value)
{
	$name2id[$value['RowName']] = $value['ID_XX'];
	unset($result[$row]);
}

echo 'done!' . $lb;
echo 'Reading resources table from LootTable_Resource.json... ';

// resources Table:
// compile the resource loot table from any generic, special or limited resources that can be looted in game
// Key: ItemId / Value: Name
$json = file_get_contents('./db/LootTable_Resource.json');
$result = json_decode($json, true);
foreach($result as $row => $value)
{
	foreach($value['GenericResource'] as $list) if($list['Resource']['RowName'] != 'None')
	{
		$unsorted['Name'][] = $items[$name2id[$list['Resource']['RowName']]]['Name'];
		$unsorted['ResourceID'][] = $name2id[$list['Resource']['RowName']];
	}
	foreach($value['SpecialResource'] as $list) if($list['Resource']['RowName'] != 'None')
	{
		$unsorted['Name'][] = $items[$name2id[$list['Resource']['RowName']]]['Name'];
		$unsorted['ResourceID'][] = $name2id[$list['Resource']['RowName']];
	}
	foreach($value['LimitedResource'] as $list) if($list['Resource']['RowName'] != 'None')
	{
		$unsorted['Name'][] = $items[$name2id[$list['Resource']['RowName']]]['Name'];
		$unsorted['ResourceID'][] = $name2id[$list['Resource']['RowName']];
	}
	unset($result[$row]);
}
// Add a few missing resources. Edit missingResources.php to change those.
include './db/missingResources.php';
// Remove some that are not resources

array_multisort($unsorted['Name'], $unsorted['ResourceID']);
foreach($unsorted['ResourceID'] as $key => $resourceID) $resources[$resourceID] = ['Name' => $unsorted['Name'][$key]];
unset($unsorted);
foreach($resources as $key => $resource)
{
	$items[$key]['Price']['Actual'] = $resources[$key]['Price'] = 1;
	$items[$key]['Price']['Calc'][0] = norm($resources[$key]['Name']) . '_' . $key;
	$items[$key]['Price']['Quantity'] = 1;
	$items[$key]['isResource'] = TRUE;
}

echo 'done!' . $lb;
echo 'Reading recipes table from RecipesTable.json... ';

// recipes Table:
// Compile the recipes table. Don't include resource materials
// Key: RecipeID / Value: Array with Name, Tier, Type, needed materials and resulting materials and their quantities
$json = file_get_contents('./db/RecipesTable.json');
$result = json_decode($json, true);
foreach($result as $row => $value)
{
	// Only accept recipes that have either ingredients or results and have those listed on the items table
	if((isset($items[$value['Ingredient1ID']]) && $value['Ingredient1Quantity'] > 0) ||	(isset($items[$value['Ingredient2ID']])	&& $value['Ingredient2Quantity'] > 0) || (isset($items[$value['Ingredient2ID']]) &&	$value['Ingredient3Quantity'] > 0) || (isset($items[$value['Ingredient2ID']]) && $value['Ingredient4Quantity'] > 0)	|| (isset($items[$value['Result1ID']]) && $value['Result1Quantity'] > 0) || (isset($items[$value['Result2ID']])	&& $value['Result2Quantity'] > 0))
	{
		$recipes[$value['RowName']]['Name'] = getName($value['RecipeName']);
		$recipes[$value['RowName']]['Tier'] = $value['Tier'];
		$recipes[$value['RowName']]['Type'] = $value['RecipeType'];
		for($index = 1; $index <= 4; $index++) if($value['Ingredient'.$index.'Quantity'] > 0)
		{
			$quantity = $value['Ingredient' . $index . 'Quantity'];
			$display_name = $items[$value['Ingredient' . $index . 'ID']]['Name'];
			$calc_name = norm($display_name) . '_' . $value['Ingredient' . $index . 'ID'];
		
			$recipes[$value['RowName']]['Recipe_Display'][$index - 1] = ($quantity > 1 ? $quantity . 'x ' . $display_name : $display_name);
			$recipes[$value['RowName']]['Recipe_Calc'][$index - 1] = ($quantity > 1 ? $quantity . '*' . $calc_name : $calc_name);
			$recipes[$value['RowName']]['Ingredients'][$index - 1] = $display_name;
			$recipes[$value['RowName']]['IngredientIDs'][$index - 1] = $value['Ingredient' . $index . 'ID'];
			$recipes[$value['RowName']]['IngredientQuantities'][$index - 1] = $value['Ingredient' . $index . 'Quantity'];
		}
		for($index = 1; $index <= 2; $index++) if($value['Result' . $index . 'Quantity'] > 0 && isset($items[$value['Result' . $index . 'ID']]))
		{
			$quantity = $value['Result' . $index . 'Quantity'];
			$display_name = $items[$value['Result' . $index . 'ID']]['Name'];
			$calc_name = norm($display_name) . '_' . $value['Result' . $index . 'ID'];
			$recipes[$value['RowName']]['Product_Display'][$index - 1] = ($quantity > 1 ? $quantity . 'x ' . $display_name : $display_name);
			$recipes[$value['RowName']]['Results'][$index - 1] = $display_name;
			$recipes[$value['RowName']]['ResultIDs'][$index - 1] = $value['Result' . $index . 'ID'];
			$recipes[$value['RowName']]['ResultQuantities'][$index - 1] = $value['Result' . $index . 'Quantity'];
		}
		// remove identical ingredients and results in a single recipe. If that means a recipe has no more ingredients or results at all, remove the recipe.
		fixCircularity($value['RowName']);
	}
	// Set the ingredient and result flags for the itemstable
	for($index = 1; $index <= 4; $index++) if($value['Ingredient' . $index . 'Quantity'] > 0) $items[$value['Ingredient' . $index . 'ID']]['isIngredient'] = True;
	for($index = 1; $index <= 2; $index++) if($value['Result' . $index . 'Quantity'] > 0 && isset($items[$value['Result' . $index . 'ID']])) $items[$value['Result' . $index . 'ID']]['isResult'] = True;
	unset($result[$row]);
}
// replace the 'Melt ice' recipe with the virtual helper recipe 'Fill flask' and add a recipe for Crimson Lotus Powder
$recipes[14201] = ['Name' => 'Fill flask', 'Tier' => 1, 'Type' => 'Material', 'Recipe_Display' => ['Water'], 'Recipe_Calc' => ['Water_11504'], 'Ingredients' => ['Water', 'Glass Flask'], 'IngredientIDs' => ['11504', '14200'], 'IngredientQuantities' => [1, 1], 'Product_Display' => ['Water-filled Glass Flask'], 'Results' => ['Water-filled Glass Flask'], 'ResultIDs' => ['14201'], 'ResultQuantities' => [1]];
$recipes[11125] = ['Name' => 'Crimson Lotus Powder', 'Tier' => 3, 'Type' => 'Material', 'Recipe_Display' => ['Crimson Lotus Flower'], 'Recipe_Calc' => ['CrimsonLotusFlower_11124'], 'Ingredients' => ['Crimson Lotus Flower'], 'IngredientIDs' => ['11124'], 'IngredientQuantities' => [1], 'Product_Display' => ['Crimson Lotus Powder'], 'Results' => ['Crimson Lotus Powder'], 'ResultIDs' => ['11125'], 'ResultQuantities' => [1]];
$items[11125]['isResult'] = TRUE; $items[11124]['isIngredient'] = TRUE;
// remove the recipes to smelt coins back into bars
unset($recipes[11054]);	// 30x Gold Coin => 1x Gold Bar
unset($recipes[11056]);	// 30x Silver Coin => 1x Silver Bar
// remove recipe to craft branches (resource) from wood
unset($recipes[10012]); // 1x Wood => 2x Branch
// some more cleaning up
foreach($resources as $key => $value) if(!$items[$key]['isIngredient']) unset($resources[$key]);
foreach($items as $key => $value) if((!$value['isResult'] && !$value['isResource']) || (!$value['isIngredient'] && !$value['isResult'] && $value['isResource'])) unset($items[$key]);

echo 'done!' . $lb;

/***************************
* Google sheets processing *
***************************/

echo 'Writing the Resources sheet... ';

// Resources sheet:
// Rows: 1.ItemID / 2.Name / 3.Price 
// Get and remove all pre-existing named ranges in the Resources sheet
$filterRequest = new Google_Service_Sheets_GetSpreadsheetByDataFilterRequest(['dataFilters' => ['a1Range' => 'Resources']]);
$namedRanges = $service->spreadsheets->getByDataFilter(SPREADSHEET_ID, $filterRequest)->namedRanges;
foreach($namedRanges as $namedRange) G_deleteNamedRange(RESOURCES_SHEET_ID, $requests, $namedRange->namedRangeId);

// Get the prices from the Prices sheet
$response = $service->spreadsheets_values->get(SPREADSHEET_ID, 'Prices!A:D');
foreach($response->values as $key => $value) if($key > 1) $prices[$value[0]] = $value[3];
// Water is a helper resource and always free
$prices['11504'] = 0;
// Silverstone always costs exactly so much that one silver coin equals 1
$prices['11052'] = '=(5/3)/(1+Trade!K12)';
unset($response);

// Populate the values array for the Resources sheet update and define the namedRanges
$values[0] = ['ItemId', 'Name', 'Price'];
foreach($resources as $id => $value)
{
	if(!isset($prices[$id])) $prices[$id] = 1;
	$values[]=[$id, $value['Name'], $prices[$id]];
	G_addNamedRange(RESOURCES_SHEET_ID, $requests, norm($value['Name']) . '_' . $id, $id, 3, count($values), 3, count($values));
}

// Set parameters for the Resources sheet update
$range = 'Resources!A1:C'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => 'USER_ENTERED'];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 3];

// Update the sheet
G_setFilterRequest(RESOURCES_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(RESOURCES_SHEET_ID, $requests, $columns['last'], $rows['last'], 1);
$service->spreadsheets_values->update(SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(SPREADSHEET_ID, $batchUpdateRequest);
unset($values); unset($requests);

echo 'done!' . $lb;
// --------------------------------------------------------------------------------
echo 'Writing the Items sheet... ';

// Items sheet:
// 1.ItemId / 2.Name / 3.Tier / 4.Type / 5.Price / 6.isIngredient / 7.isResult / 8.isResource
// Get and remove all pre-existing named ranges in the Items sheet
$filterRequest = new Google_Service_Sheets_GetSpreadsheetByDataFilterRequest(['dataFilters' => ['a1Range' => 'Items']]);
$namedRanges = $service->spreadsheets->getByDataFilter(SPREADSHEET_ID, $filterRequest)->namedRanges;
foreach($namedRanges as $namedRange) G_deleteNamedRange(ITEMS_SHEET_ID, $requests, $namedRange->namedRangeId);

// Populate the values array for the Items sheet update and define the namedRanges
$values[0]=['ItemId', 'Name', 'Tier', 'Type', 'Price', 'isIngredient', 'isResult', 'isResource'];
foreach($items as $id => $value)
{
	$price = setPrice($id);
	$formula = ($price['Quantity'] > 1 ? '=(' . implode('+', $price['Calc']) . ')/' . $price['Quantity'] : '=' . implode('+', $price['Calc']));
	$values[]=[$id, $value['Name'], $items[$id]['Tier'], $value['Type'], $formula, $value['isIngredient'], $value['isResult'], $value['isResource']];
	if(!$value['isResource']) G_addNamedRange(ITEMS_SHEET_ID, $requests, norm($value['Name']) . '_' . $id, $id, 5, count($values), 5, count($values));
}

// Set parameters for the Items sheet update
$range = 'Items!A1:H'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 8];
G_addNamedRange(ITEMS_SHEET_ID, $requests, 'ItemNames', '110', 2, $rows['firstData'], 2, $rows['lastData'] - 1);
G_addNamedRange(ITEMS_SHEET_ID, $requests, 'ItemTable', '120', 2, $rows['firstData'], 5, $rows['lastData'] - 1);
G_addNamedRange(ITEMS_SHEET_ID, $requests, 'ItemTypes', '130', 4, $rows['firstData'], 4, $rows['lastData'] - 1);
G_setFilterRequest(ITEMS_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(ITEMS_SHEET_ID, $requests, $columns['last'], $rows['last'], $rows['lastHeadline']);

// Update the spreadsheet
$service->spreadsheets_values->update(SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(SPREADSHEET_ID, $batchUpdateRequest);
unset($values); unset($requests);

echo 'done!' . $lb;
// --------------------------------------------------------------------------------
echo 'Writing the Recipes sheet... ';

// Recipes sheet:
// 1.RecipeId / 2.Name / 3.Tier / 4.Type / 5.Price / 6.Ingredients / 7.Results
// Format the values for the Recipes spreadsheet update
$values[0]=['RecipeId', 'Name', 'Tier', 'Type', 'Price', 'Ingredients', 'Result'];
foreach($recipes as $id => $value) $values[]=[$id, $value['Name'], $value['Tier'], $value['Type'], '=' . implode('+', $value['Recipe_Calc']), implode(' + ', $value['Recipe_Display']), implode(' + ', $value['Product_Display'])];
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);

// Set parameters for the Recipes spreadsheet update
$range = 'Recipes!A1:G'.count($values);
$rows = ['firstHeadline' => 1, 'lastHeadline' => 1, 'firstData' => 2, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 7];
G_setFilterRequest(RECIPES_SHEET_ID, $requests, $columns['first'], $rows['lastHeadline'], $columns['last'], $rows['lastData']);
G_setGridSize(RECIPES_SHEET_ID, $requests, $columns['last'], $rows['last'], $rows['lastHeadline']);

// Update the spreadsheet
$service->spreadsheets_values->update(SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(SPREADSHEET_ID, $batchUpdateRequest);
unset($values); unset($requests);

echo 'done!' . $lb;
// --------------------------------------------------------------------------------
echo 'Updating the Prices sheet... ';

// Prices sheet:
// Rows: 1.ItemID / 2.Name / 3.Price now / 4.Price set / 5. Price diff
// Update the Prices sheet
$values[0] = ['ItemId', 'Resource', 'Price (in silver)'];
$values[1] = ['', '', 'now', 'set', 'diff'];
$row = 2;
foreach($resources as $id => $value)
{
	if($id <> '11504' && $id <> '11052')
	{
		$row++;
		$values[]=[$id, $value['Name'],'=' . norm($value['Name']) . '_' . $id, $prices[$id], '=ABS(C' . $row . '-D' . $row . ')'];
	}
}

// Set parameters for the Resources sheet update
$range = 'Prices!A1:E'.count($values);
$valueRange = new Google_Service_Sheets_ValueRange(['values' => $values]);
$params = ['valueInputOption' => 'USER_ENTERED'];
$rows = ['firstHeadline' => 1, 'lastHeadline' => 2, 'firstData' => 3, 'lastData' => count($values), 'last' => count($values)];
$columns = ['first' => 1, 'last' => 5];

// Update the sheet
G_setGridSize(PRICES_SHEET_ID, $requests, $columns['last'], $rows['last'], 2);
$service->spreadsheets_values->update(SPREADSHEET_ID, $range, $valueRange, $params);
$batchUpdateRequest = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
$response = $service->spreadsheets->batchUpdate(SPREADSHEET_ID, $batchUpdateRequest);
unset($values); unset($requests);

echo 'done!' . $lb;

$etime = microtime(true);
$diff = $etime - $stime;
echo $lb . $lb . 'Required time: '.round($diff,3).' sec.';
if(isset($_SERVER['REMOTE_ADDR'])) echo "</pre>";
?>