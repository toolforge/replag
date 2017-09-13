<?php if ( isset( $_GET['source'] ) ) { show_source(__FILE__); exit(); } ?>
<!DOCTYPE html>
<!--
Copyright (c) 2015-2017 Bryan Davis <bd808@wikimedia.org>

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
th.host {border:none;text-align:left;}
.shard {text-align:center;}
.lag, .time {text-align:right;}
.lagged {background-color:#fee;}
footer {margin-top:2em;padding-top:1em;border-top:1px solid #333;text-align:right;}
#powered-by {float:left;}
@media only screen and (min-width: 768px) {
	#by-host {column-count:2;}
}
</style>
</head>
<body>
<header>
<h1>Replag reported by heartbeat_p</h1>
<p>Wikimedia Cloud Services <a href="https://wikitech.wikimedia.org/wiki/Help:Toolforge/Database">Wiki Replicas</a> lag as reported by the <a href="https://lists.wikimedia.org/pipermail/labs-l/2015-November/004143.html">heartbeat_p database</a>.</p>
</header>
<section id="by-host">
<?php
/** @var array $cluster hostnames */
$cluster = array(
	'wikireplica-analytics.eqiad.wmnet',
	'wikireplica-web.eqiad.wmnet',
	'c1.labsdb',
	'c3.labsdb',
);

/** @var array $slices slice names */
$slices = array(
	's1',
	's2',
	's3',
	's4',
	's5',
	's6',
	's7',
);

/** @var array $replag host => slice => lag */
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

// Get lag data for each slice from the heartbeat_p db on each host
foreach ( $cluster as $host) {
	$replag[$host] = array();
	foreach ( $slices as $slice ) {
		$dbh = connect( 'heartbeat_p', $host );
		$stmt = $dbh->prepare(
			'SELECT lag FROM heartbeat WHERE shard = ?' );
		$stmt->execute( array( $slice ) );
		$replag[$host][$slice] = $stmt->fetchColumn();
		$stmt->closeCursor();
	}
}

// Print replag data for each shard on each host
foreach ( $replag as $host => $shards ) {
	$shost = htmlspecialchars( $host );
?>
<table id="<?= $shost ?>">
<thead>
<tr><th class="host" colspan="3"><?= $shost ?></th></tr>
<tr><th class="shard">Shard</th>
<th class="lag">Lag (seconds)</th>
<th class=time">Lag (time)</th>
</tr></thead>
<tbody>
<?php
	foreach ( $shards as $shard => $lag ) {
		echo '<tr class="', ( ( $lag > 0 ) ? 'lagged' : '' ), '">';
		echo '<td class="shard">', htmlspecialchars( $shard ), '</td>';
		echo '<td class="lag">', htmlspecialchars( $lag ), '</td>';
		echo '<td class="time">', secondsAsTime( $lag ), '</td></tr>';
	}
?>
</tbody>
</table>
<?php
} //end foreach ( $replag )
?>
</section>
<footer>
<div id="powered-by">
<a href="/"><img src="https://tools-static.wmflabs.org/toolforge/banners/Powered-by-Toolforge.png" alt="Powered by Wikimedia Toolforge"></a>
</div>
<a id="source" href="?source">view source</a>
</footer>
</body>
</html>
