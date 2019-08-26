<?php

/**
 * MySQL Slow
 *
 * @author <masterklavi@gmail.com>
 * @version 0.1
 */

// helper
if ($argc === 1 || $argv[1] === '--help')
{
    print 'Usage: php mysqlslow.php path/to/slow.log > output.htm'.PHP_EOL;
    exit;
}

// params
$filename = $argv[1];

file_put_contents('php://stderr', 'min freq: ');
$min_freq = (int)trim(readline());

file_put_contents('php://stderr', 'explain (y/n)? ');
$explain = trim(readline()) === 'y';

if ($explain) {
    file_put_contents('php://stderr', 'host: ');
    $host   = trim(readline());

    file_put_contents('php://stderr', 'port: ');
    $port   = trim(readline());

    file_put_contents('php://stderr', 'name: ');
    $dbname   = trim(readline());

    file_put_contents('php://stderr', 'username: ');
    $dbuser   = trim(readline());

    file_put_contents('php://stderr', 'password: ');
    $dbpass   = trim(readline());
}



$time = microtime(true);

if (!is_readable($filename))
{
    throw new Exception();
}

$handle = fopen($filename, 'r');
if ($handle === false)
{
    throw new Exception();
}

while (($line = fgets($handle)) !== false)
{
    if ($line{0} === '#' && $line{2} !== ' ')
    {
        break;
    }
}

$map = [
    'qt' => 'query_time',
    'lt' => 'lock_time',
    'rs' => 'rows_sent',
    're' => 'rows_examined',
    'bs' => 'bytes_sent',
];

$items = [];

while (true)
{
    $meta = [];

    while ($line{0} === '#' && $line{2} !== ' ')
    {
        if (preg_match('/^# Time: (.+)/', $line, $match))
        {
            $meta['datetime'] = $match[1];
        }
        elseif (preg_match('/^# User@Host: (.+)\[.*\] @ (.+)\[.*\]\s+Id:\s+(\d+)/', $line, $match))
        {
            $meta['user'] = $match[1];
            $meta['host'] = trim($match[2]);
            $meta['id'] = $match[3];
        }
        elseif (preg_match('/^# Schema: (\S+)\s+Last_errno: (\d+)  Killed: 0/', $line, $match))
        {
            $meta['schema'] = $match[1];
            $meta['errno'] = $match[2];
        }
        elseif (preg_match('/^# Query_time:\s+([0-9\.]+)\s+Lock_time:\s+([0-9\.]+)\s+Rows_sent:\s+(\d+)\s+Rows_examined:\s+(\d+)/', $line, $match))
        {
            $meta['query_time'] = $match[1];
            $meta['lock_time'] = $match[2];
            $meta['rows_sent'] = $match[3];
            $meta['rows_examined'] = $match[4];
        }
        elseif (preg_match('/^# Bytes_sent:\s+(\d+)/', $line, $match))
        {
            $meta['bytes_sent'] = $match[1];
        }
        else
        {
            trigger_error('parse error: '.substr($line, 0, 100));
            die;
        }

        do
        {
            $line = fgets($handle);
        }
        while (strpos($line, '/usr/sbin/mysqld') === 0 || strpos($line, 'Tcp port:') === 0 || strpos($line, 'Time') === 0);
    }

    $sql = '';
    while ($line{0} !== '#' || $line{2} === ' ')
    {
        $sql .= $line;

        do
        {
            $line = fgets($handle);
        }
        while (strpos($line, '/usr/sbin/mysqld') === 0 || strpos($line, 'Tcp port:') === 0 || strpos($line, 'Time') === 0);

        if ($line === false)
        {
            break;
        }
    }

    if ($meta['user'] !== 'root')
    {
        if (preg_match('#LIMIT\s*(\d+),\s*(\d+)#i', $sql, $match))
        {
            $offset = $match[1];
            $limit = $match[2];
        }
        elseif (preg_match('#LIMIT\s*(\d+)#i', $sql, $match))
        {
            $offset = 0;
            $limit = $match[1];
        }
        else
        {
            $offset = 0;
            $limit = 0;
        }

        $clear = $sql = preg_replace('#SET timestamp=\d+;\s+#', '', $sql);

        $clear = preg_replace('#\s*[<>!=]+\s*#', ' $0 ', $clear);
        $clear = preg_replace('#\s+#', ' ', $clear);
        $clear = str_replace('( ', '(', $clear);
        $clear = str_replace(' )', ')', $clear);
        $clear = trim($clear);

        do
        {
            $clear = preg_replace('#\((\([^\(\)]+\))\)#', '$1', $clear, -1, $cnt);
        }
        while ($cnt > 0);

        do
        {
            $clear = str_replace('(1 = 1)', '1 = 1', $clear, $cnt);
            $clear = str_ireplace('1 = 1 OR 1 = 1', '1 = 1', $clear);
            $clear = str_ireplace('1 = 1 AND ', '', $clear);
            $clear = str_ireplace('AND 1 = 1', '', $clear);
        }
        while ($cnt > 0);

        $clear = str_replace('`', '', $clear);
        $clear = preg_replace('#SELECT .+? FROM#i', 'SELECT ... FROM', $clear);
        $clear = preg_replace('# IN \([\d, \']+\)#i', ' IN (?, )', $clear);
        $clear = preg_replace_callback("#'([^']+)'#", function ($m) { return is_numeric($m[1]) ? "'?'" : "'...'"; }, $clear);
        $clear = preg_replace('# IN \([\d, \'\.]+\)#i', " IN ('...', )", $clear);
        $clear = preg_replace('#([= \(])\d+(\D)#', '$1?$2', $clear);

        $hash = md5($clear);

        if (isset($items[$hash]))
        {
            $aggr = $items[$hash]['aggr'];

            $aggr['count']++;

            foreach ($map as $k => $v)
            {
                $aggr['sum_'.$k] += $meta[$v];
                $aggr['max_'.$k] >= $meta[$v] OR $aggr['max_'.$k] = $meta[$v];
                $aggr['avg_'.$k] = ($aggr['avg_'.$k] * ($aggr['count'] - 1) + $meta[$v]) / $aggr['count'];
            }

            $aggr['max_offset'] >= $offset OR $aggr['max_offset'] = $offset;
            $aggr['max_limit'] >= $limit OR $aggr['max_limit'] = $limit;

            $items[$hash]['aggr'] = $aggr;
        }
        else
        {
            $aggr = ['count' => 1, 'max_offset' => $offset, 'max_limit' => $limit];
            foreach ($map as $k => $v)
            {
                $aggr['sum_'.$k] = $aggr['max_'.$k] = $aggr['avg_'.$k] = $meta[$v];
            }

            $items[$hash] = ['clear' => $clear, 'sql' => $sql, 'aggr' => $aggr];
        }
    }

    if ($line === false)
    {
        break;
    }
}

fclose($handle);

if ($explain)
{
    $db = new mysqli($host, $dbuser, $dbpass, $dbname, $port);
    if ($db->connect_error)
    {
        throw new Exception($db->connect_error);
    }

    $db->set_charset('utf8');
}

$filtered = [];
$i = 0;
foreach ($items as $item)
{
    ++$i;

    if ($item['aggr']['count'] < $min_freq)
    {
        continue;
    }

    $clear = htmlspecialchars($item['clear']);
    unset($item['clear']);

    $sub_sqls = [];
    collect_sqls($sub_sqls, $clear);

    $clear = pretty_sql($clear);

    foreach ($sub_sqls as $n => $s)
    {
        $clear .= PHP_EOL.PHP_EOL.'sql#'.($n+1).':'.PHP_EOL.pretty_sql(trim($s, '()'));
    }

    $item['pretty'] = $clear;

    if ($explain)
    {
        $result = $db->query('EXPLAIN '.$item['sql']);
        if ($db->error)
        {
            throw new Exception($db->error);
        }

        $item['explain'] = [];
        $item['explain_rows'] = 0;
        while ($row = $result->fetch_assoc())
        {
            $item['explain_rows'] += $row['rows'];
            $item['explain'][] = $row;
        }

        $result->close();
    }

    file_put_contents('php://stderr', "\rhandling... ".$i.'/'.count($items));

    $filtered[] = $item;
}
unset($items);
file_put_contents('php://stderr', "\rok".str_repeat(' ', 100)."\n");

if ($explain)
{
    $db->close();
}

$time = microtime(true) - $time;

function collect_sqls(&$sub_sqls, &$b)
{
    for ($j = 0; $j < 100; $j++)
    {
        $p = stripos($b, '(SELECT ');
        if ($p === false)
        {
            break;
        }

        $o1 = 1;
        $o2 = 0;
        for ($i = $p+8; $i < strlen($b); ++$i)
        {
            if ($b{$i} === '(')
            {
                ++$o1;
            }
            elseif ($b{$i} === ')')
            {
                ++$o2;

                if ($o2 === $o1)
                {
                    $sub_sqls[] = substr($b, $p, $i+1-$p);
                    $b = substr_replace($b, '(<i>sql#'.count($sub_sqls).'</i>)', $p, $i+1-$p);
                    break;
                }
            }
        }
    }
}

function pretty_sql($clear)
{
    $call = function ($m) {
        return $m[1].'<i>'.strtoupper($m[2]).'</i> ';
    };

    do
    {
        $clear = preg_replace_callback('#(^|\s|\()(SELECT|FROM|WHERE|(?:LEFT |INNER |RIGHT |CROSS )?JOIN|GROUP BY|ORDER BY|LIMIT|HAVING|ON|LIKE|OR|AND|IS|IN|BETWEEN|NOT|NULL|ASC|DESC) #i', $call, $clear, -1, $cnt);
    }
    while ($cnt > 0);

    $clear = preg_replace('#(?:LEFT |INNER |RIGHT |CROSS )?JOIN#', "\n $0", $clear);
    $clear = str_replace('ORDER BY', "\nORDER BY", $clear);
    $clear = str_replace('GROUP BY', "\nGROUP BY", $clear);
    $clear = str_replace('WHERE', "\nWHERE", $clear);
    $clear = str_replace('HAVING', "\nHAVING", $clear);
    $clear = str_replace('LIMIT', "\nLIMIT", $clear);

    $clear = str_ireplace("('...', )", "<b>('...', )</b>", $clear);
    $clear = str_ireplace("(?, )", "<b>(?, )</b>", $clear);
    $clear = str_ireplace("'...'", "<b>'...'</b>", $clear);
    $clear = str_ireplace("'?'", "<b>'?'</b>", $clear);
    $clear = str_ireplace(" ?", " <b>?</b>", $clear);

    return $clear;
}

?>
<!doctype html>
<html>
    <head>
        <title>MySQL Slow</title>

        <link rel="stylesheet" href="theme.blue.css" type="text/css" />

        <style>
            table {
                background: #eee;
                border-spacing: 1px;
            }
            table td, table th {
                background: #fff;
            }
            table td {
                font: 12px monospace;
                text-align: right;
                padding-left: 6px;
            }
            td.sql {
                text-align: left;
                padding: 0;
                width: 800px;
            }
            td.sql div {
                text-decoration: none;
                color: #666;
                font: 11px monospace;
                white-space: pre-wrap;
            }
            td.sql i {
                font-style: normal;
                color: #66c;
            }
            td.sql b {
                font-weight: normal;
                color: #f99;
            }
        </style>

        <script type="text/javascript" src="jquery-latest.min.js"></script>
        <script type="text/javascript" src="jquery.tablesorter.min.js"></script>

        <script type="text/javascript">
            function show_sql(el) {
                var win = window.open('', 'SQL', 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=1000,height=500,top=200,left=300');
                win.document.body.innerHTML = '<pre>' + $(el).parent().find('span').html() + '</pre>';
            }

            function show_explain(el) {
                var win = window.open('', 'Explain', 'toolbar=no,location=no,directories=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=1600,height=300,top=200,left=100');
                win.document.body.innerHTML = '<style>'
                                                + 'table{border-spacing:1px;background:#eee;font:10px monospace;}'
                                                + 'table td, table th{background:#fff;word-break:break-all;}'
                                                + '</style>'
                                                + $(el).parent().find('div').html();
            }

            $(document).ready(function() {
                $('.tablesorter').tablesorter();
                $('table#root > thead th').each(function(i, e) {
                    var el = $(e);
                    el.css('width', el.width() + 'px');
                });
                $('table#root > tbody tr:first-child td').each(function(i, e) {
                    var el = $(e);
                    el.css('width', el.width() + 'px');
                });
                $('table#root > thead').css({display: 'block'});
                $('table#root > tbody').css({display: 'block', height: '700px', overflow: 'auto'});
            });
        </script>
    </head>
    <body>
        <h2>MySQL Slow [<?=round($time, 2)?> sec]</h2>
        <small>Author: masterklavi@gmail.com</small>
        <br/>
        <br/>

        <table id="root" class="tablesorter">
            <thead>
                <tr>
                    <th rowspan="2">Count</th>
                    <th colspan="5">Summary</th>
                    <th colspan="5">Maximum</th>
                    <th colspan="5">Average</th>
                    <th rowspan="2">Limit</th>
                    <th rowspan="2">Offset</th>
                    <th rowspan="2">Query</th>

                    <?php if ($explain): ?>
                        <th rowspan="2">Explain</th>
                    <?php endif; ?>

                    <th rowspan="2">Links</th>
                </tr>
                <tr>
                    <th>qt, s</th>
                    <th>lt, ms</th>
                    <th>rs</th>
                    <th>re</th>
                    <th>bs, kb</th>

                    <th>qt, s</th>
                    <th>lt, ms</th>
                    <th>rs</th>
                    <th>re</th>
                    <th>bs, kb</th>

                    <th>qt, s</th>
                    <th>lt, ms</th>
                    <th>rs</th>
                    <th>re</th>
                    <th>bs, kb</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered as $item): ?>
                    <tr>
                        <td><?=$item['aggr']['count']?></td>

                        <td><?=round($item['aggr']['sum_qt'])?></td>
                        <td><?=round($item['aggr']['sum_lt']*1000)?></td>
                        <td><?=$item['aggr']['sum_rs']?></td>
                        <td><?=$item['aggr']['sum_re']?></td>
                        <td><?=ceil($item['aggr']['sum_bs']/1024)?></td>

                        <td><?=round($item['aggr']['max_qt'], 3)?></td>
                        <td><?=round($item['aggr']['max_lt']*1000, 3)?></td>
                        <td><?=$item['aggr']['max_rs']?></td>
                        <td><?=$item['aggr']['max_re']?></td>
                        <td><?=ceil($item['aggr']['max_bs']/1024)?></td>

                        <td><?=round($item['aggr']['avg_qt'], 3)?></td>
                        <td><?=round($item['aggr']['avg_lt']*1000, 3)?></td>
                        <td><?=round($item['aggr']['avg_rs'])?></td>
                        <td><?=round($item['aggr']['avg_re'])?></td>
                        <td><?=ceil($item['aggr']['avg_bs']/1024)?></td>

                        <td><?=$item['aggr']['max_limit']?></td>
                        <td><?=$item['aggr']['max_offset']?></td>

                        <td class="sql">
                            <div contenteditable=""><?=$item['pretty']?></div>
                        </td>

                        <?php if ($explain): ?>
                            <td><?=$item['explain_rows']?></td>
                            <td>
                                <a href="javascript:void(0);" onclick="show_sql(this);">Q</a>
                                <a href="javascript:void(0);" onclick="show_explain(this);">E</a>
                                <span style="display: none;"><?=htmlspecialchars($item['sql'])?></span>
                                <div style="display: none;">
                                    <table>
                                        <thead>
                                            <th>id</th>
                                            <th>select_type</th>
                                            <th>table</th>
                                            <th>type</th>
                                            <th>possible_keys</th>
                                            <th>key</th>
                                            <th>key_len</th>
                                            <th>ref</th>
                                            <th>rows</th>
                                            <th>Extra</th>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($item['explain'] as $row): ?>
                                                <tr>
                                                    <?php foreach ($row as $v): ?>
                                                        <td><?=htmlspecialchars($v)?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>

                        <?php else: ?>
                            <td>
                                <a href="javascript:void(0);" onclick="show_sql(this);">Q</a>
                                <span style="display: none;"><?=htmlspecialchars($item['sql'])?></span>
                            </td>

                        <?php endif; ?>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>

    </body>
</html>
