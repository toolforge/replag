<!DOCTYPE html>
<!--
Copyright (c) 2015-2017 Wikimedia Foundation and contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
-->
<html>
<head>
<title>Replag via heartbeat_p</title>
<style>
/* http://meyerweb.com/eric/tools/css/reset/ 
   v2.0 | 20110126
   License: none (public domain)
*/
html, body, div, span, applet, object, iframe, h1, h2, h3, h4, h5, h6, p, blockquote, pre, a, abbr, acronym, address, big, cite, code, del, dfn, em, img, ins, kbd, q, s, samp, small, strike, strong, sub, sup, tt, var, b, u, i, center, dl, dt, dd, ol, ul, li, fieldset, form, label, legend, table, caption, tbody, tfoot, thead, tr, th, td, article, aside, canvas, details, embed, figure, figcaption, footer, header, hgroup, menu, nav, output, ruby, section, summary, time, mark, audio, video {margin:0;padding:0;border:0;font-size:100%;font:inherit;vertical-align:baseline;}
article, aside, details, figcaption, figure, footer, header, hgroup, menu, nav, section {display:block;}
body {line-height:1;}
ol, ul {list-style:none;}
blockquote, q {quotes:none;}
blockquote:before, blockquote:after, q:before, q:after {content:'';content:none;}
</style>
<style>
body {background-color:#fefefe;color:#333;font-family:monospace;padding:2em;}
h1 {font-size:1.5em;font-weight:bold;margin-bottom:1.5em;}
p {margin-bottom:1em;}
table {border-spacing:1em 0;margin-bottom:1em;}
th, td {padding:.2em;margin:.2em;}
th {font-weight:bold;border-bottom:1px solid #333;}
.shard {text-align:center;}
.lag, .time {text-align:right;}
.lagged {background-color:#fee;}
.header {padding-right:10px;background-position:right center;background-repeat:no-repeat;background-image:url("data:image/gif;base64,R0lGODlhBwAJAIABACMtMP///yH5BAEKAAEALAAAAAAHAAkAAAINjGEJq8sOk4Qu0IZmKgA7");}
.headerSortUp {background-image:url("data:image/gif;base64,R0lGODlhBwAEAIABACMtMP///yH5BAEKAAEALAAAAAAHAAQAAAIIhA+BGWoNWSgAOw==");}
.headerSortDown {background-image:url("data:image/gif;base64,R0lGODlhBwAEAIABACMtMP///yH5BAEKAAEALAAAAAAHAAQAAAIHjGEJq8sOCwA7");}
footer {margin-top:2em;padding-top:1em;border-top:1px solid #333;text-align:right;}
#powered-by {float:left;}
</style>
</head>
<body>
<header>
<h1>Replag reported by heartbeat_p</h1>
<p>Wikimedia Cloud Services <a href="https://wikitech.wikimedia.org/wiki/Help:Toolforge/Database">Wiki Replicas</a> lag as reported by the <a href="https://lists.wikimedia.org/pipermail/labs-l/2015-November/004143.html">heartbeat_p database</a>.</p>
</header>
<section>
<?php
/** @var array $wikis dbname => slice */
$wikis = array();
/** @var array $slices slice => hostname */
$slices = array();
/** @var array $replag slice => lag */
$replag = array();

// Read database credentials from replica.my.cnf
$cnf = parse_ini_file( '../replica.my.cnf' );

/**
 * Connect to a MySQL database.
 * @param string $db Database name
 * @param string $host Database server
 * @return PDO Database connection
 */
function connect( $db, $host ) {
	global $cnf;
	return new PDO(
		"mysql:dbname={$db};host={$host}",
		$cnf['user'],
		$cnf['password'],
		array(
			PDO::ATTR_TIMEOUT => 5,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		)
	);
}

/**
 * Format a count of seconds as a pretty time interval.
 * @param int $seconds
 * @return string Time interval in <hours>:<minutes>:<seconds> format
 */
function secondsAsTime( $seconds ) {
	return sprintf(
		'%02d:%02d:%02d',
		floor( $seconds / 3600 ),
		( $seconds / 60 ) % 60,
		$seconds % 60
	);
}

// Get list of all databases and the slices they live on from meta_p.wiki
$dbh = connect( 'meta_p', 's7.labsdb' );
$stmt = $dbh->query( 'SELECT dbname, slice FROM wiki ORDER BY dbname' );
$res = $stmt->fetchAll( PDO::FETCH_ASSOC );
$stmt->closeCursor();

// Populate $wikis and $slices from meta_p.wiki results
foreach ( $res as $row ) {
	list( $slice, $domain ) = explode( '.', $row['slice'] );
	$wikis[$row['dbname']] = $slice;
	$slices[$slice] = $row['slice'];
}

// Get lag data for each slice from the heartbeat_p db on the matching host
foreach ( $slices as $slice => $host ) {
	$dbh = connect( 'heartbeat_p', $host );
	$stmt = $dbh->prepare( 'SELECT lag FROM heartbeat WHERE shard = ?' );
	$stmt->execute( array( $slice ) );
	$replag[$slice] = $stmt->fetchColumn();
	$stmt->closeCursor();
}
?>
<table id="by-shard">
<thead><tr>
<th class="shard">Shard</th>
<th class="lag">Lag (seconds)</th>
<th class=time">Lag (time)</th>
</tr></thead>
<tbody>
<?php
// Print replag data for each shard
ksort( $replag );
foreach ( $replag as $shard => $lag ) {
	echo '<tr class="', ( ( $lag > 0 ) ? 'lagged' : '' ), '">';
	echo '<td class="shard">', htmlspecialchars( $shard ), '</td>';
	echo '<td class="lag">', htmlspecialchars( $lag ), '</td>';
	echo '<td class="time">', secondsAsTime( $lag ), '</td></tr>';
}
?>
</tbody>
</table>

<table id="by-wiki">
<thead><tr>
<th class="wiki">Wiki</th>
<th class="shard">Shard</th>
<th class="lag">Lag (seconds)</th>
<th class=time">Lag (time)</th>
</tr></thead>
<tbody>
<?php
// Print shard replag data for each database
foreach ( $wikis as $wiki => $shard ) {
	$lag = $replag[$shard];
	echo '<tr class="', ( ( $lag > 0 ) ? 'lagged' : '' ), '">';
	echo '<td class="wiki">', htmlspecialchars( $wiki ), '</td>';
	echo '<td class="shard">', htmlspecialchars( $shard ), '</td>';
	echo '<td class="lag">', htmlspecialchars( $lag ), '</td>';
	echo '<td class="time">', secondsAsTime( $lag ), '</td></tr>';
}
?>
</tbody>
</table>
</section>
<footer>
<div id="powered-by">
<a href="/"><img src="https://tools-static.wmflabs.org/toolforge/banners/Powered-by-Toolforge.png" alt="Powered by Wikimedia Toolforge"></a>
</div>
<a id="source" href="https://phabricator.wikimedia.org/source/tool-replag/">view source</a>
</footer>
<script src="https://tools-static.wmflabs.org/static/jquery/2.1.0/jquery.min.js"></script>
<script src="https://tools-static.wmflabs.org/static/jquery-tablesorter/2.0.5/jquery.tablesorter.min.js"></script>
<script lang="javascript">
$(document).ready(function(){
	$('#by-wiki').tablesorter({sortList:[[2,1]]});
});
</script>
</body>
</html>
