<?php
header("Content-Type:text/xml; charset=utf-8");
require('class.NYPLMenuFeed.php');
//pagination
$pg = 1;
if (isset($_GET['pg'])){
    $pg = (int)$_GET['pg']; //cast to int
}
if($pg <= 0){$pg=1;} //correct for bad data

//feed object
$feed = new NYPLMenuFeed($pg);
$feed->getFeed();
echo $feed->output();
?>
