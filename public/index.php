<!DOCTYPE html>
<!--
Copyright (c) 2015-2020 Wikimedia Foundation and contributors

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
.slice {text-align:center;}
.lag, .time {text-align:right;}
.lagged {background-color:#fee;}
p.lagged {padding:.5em;border:double #f33;}
.header {padding-right:10px;background-position:right center;background-repeat:no-repeat;background-image:url("data:image/gif;base64,R0lGODlhBwAJAIABACMtMP///yH5BAEKAAEALAAAAAAHAAkAAAINjGEJq8sOk4Qu0IZmKgA7");}
.headerSortUp {background-image:url("data:image/gif;base64,R0lGODlhBwAEAIABACMtMP///yH5BAEKAAEALAAAAAAHAAQAAAIIhA+BGWoNWSgAOw==");}
.headerSortDown {background-image:url("data:image/gif;base64,R0lGODlhBwAEAIABACMtMP///yH5BAEKAAEALAAAAAAHAAQAAAIHjGEJq8sOCwA7");}
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
<p>Wikimedia Cloud Services <a href="https://wikitech.wikimedia.org/wiki/Help:Toolforge/Database">Wiki Replicas</a> replication lag as reported by the <a href="https://lists.wikimedia.org/pipermail/labs-l/2015-November/004143.html">heartbeat_p database</a>.</p>
</header>
<section id="by-host">
<?php
/** @var array $clusters hostnames */
$clusters = array(
	'analytics.db.svc.wikimedia.cloud',
	'web.db.svc.wikimedia.cloud',
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
	's8',
);

/** @var array $replag host => slice => lag */
$replag = [];

/** @var array $maxSectionReplag max replag in a given section */
$maxSectionReplag = array_fill_keys( $slices, 0 );

/** @var array $wikis dbname => slice */
$wikis = [];

// Read database credentials from replica.my.cnf
$cnf = parse_ini_file(
	posix_getpwuid( posix_getuid() )['dir'] . '/replica.my.cnf' );

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
	if ( $seconds === PHP_INT_MAX ) {
		return 'N/A';
	}
	return sprintf(
		'%02d:%02d:%02d',
		floor( $seconds / 3600 ),
		( $seconds / 60 ) % 60,
		$seconds % 60
	);
}

// Get lag data for each slice from the heartbeat_p db on each host
foreach ( $clusters as $cluster ) {
	$replag[$cluster] = array();
	foreach ( $slices as $slice ) {
		$host = "{$slice}.{$cluster}";
		try {
			$dbh = connect( 'heartbeat_p', $host );
			$stmt = $dbh->prepare(
				'SELECT lag FROM heartbeat WHERE shard = ?' );
			$stmt->execute( array( $slice ) );
			$replag[$cluster][$slice] = $stmt->fetchColumn();
			$maxSectionReplag[$slice] = max( $maxSectionReplag[$slice], $replag[$cluster][$slice] );
			$stmt->closeCursor();
		} catch ( PDOException $e ) {
			$replag[$cluster][$slice] = PHP_INT_MAX;
		}
	}
}

// Print replag data for each slice on each host
$lagged = false;
foreach ( $replag as $host => $slices ) {
	$shost = htmlspecialchars( $host );
?>
<table id="<?= $shost ?>">
<thead>
<tr><th class="host" colspan="3"><?= $shost ?></th></tr>
<tr><th class="slice">Section</th>
<th class="lag">Lag (seconds)</th>
<th class="time">Lag (time)</th>
</tr></thead>
<tbody>
<?php
	foreach ( $slices as $slice => $lag ) {
		$class = '';
		if ( $lag > 0 ) {
			$lagged = true;
			$class = 'lagged';
		}
		echo '<tr class="', $class, '">';
		echo '<td class="slice">', htmlspecialchars( $slice ), '</td>';
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
<?php
if ( $lagged ) {
?>
<aside>
<p class="lagged">Please check for <a href="https://wikitech.wikimedia.org/wiki/Map_of_database_maintenance">active database maintenance</a> before reporting replication lag issues on IRC or Phabricator.</p>
</aside>
<?php
} //end if ( $lagged )
?>
<section>
<?php
// Reset accumulators for per-wiki stats
$replag = array();
$slices = array();

try {
	// Get list of all databases and the slices they live on from meta_p.wiki
	$dbh = connect( 'meta_p', 's7.web.db.svc.wikimedia.cloud' );
	$stmt = $dbh->query( 'SELECT dbname, slice FROM wiki ORDER BY dbname' );
	$res = $stmt->fetchAll( PDO::FETCH_ASSOC );
	$stmt->closeCursor();

	// Populate $wikis and $slices from meta_p.wiki results
	foreach ( $res as $row ) {
		list( $slice, $domain ) = explode( '.', $row['slice'] );
		$wikis[$row['dbname']] = $slice;
		$slices[$slice] = $row['slice'];
	}
} catch ( PDOException $e ) {
	// TODO: better error reporting
}
?>
<table id="by-wiki">
<thead><tr>
<th class="wiki">Database</th>
<th class="slice">Section</th>
<th class="lag">Lag (seconds)</th>
<th class="time">Lag (time)</th>
</tr></thead>
<tbody>
<?php
// Print slice replag data for each database
foreach ( $wikis as $wiki => $section ) {
	$lag = $maxSectionReplag[$section];
	echo '<tr class="', ( ( $lag > 0 ) ? 'lagged' : '' ), '">';
	echo '<td class="wiki">', htmlspecialchars( $wiki ), '.{analytics,web}.db.svc.wikimedia.cloud</td>';
	echo '<td class="slice">', htmlspecialchars( $section ), '</td>';
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
<a id="source" href="https://gitlab.wikimedia.org/toolforge-repos/replag/">view source</a>
</footer>
<script src="https://tools-static.wmflabs.org/cdnjs/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://tools-static.wmflabs.org/cdnjs/ajax/libs/jquery.tablesorter/2.31.3/js/jquery.tablesorter.min.js"></script>
<script lang="javascript">
$( document ).ready( function(){
	$( '#by-wiki' ).tablesorter( { sortList:[[2,1]] } );
});
</script>
</body>
</html>
