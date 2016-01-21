<?php
/* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
wait-for-latest-fansubs
Copyright (C) 2015 vknkk

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
* * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * */

define('DIRS_PATH', 'G:/Anime/share/new');
define('SCRIPT_PATH',  __DIR__.'/db.json');
define('KG_URL_PRFX', 'http://fansubs.ru/');
date_default_timezone_set('Europe/Kiev');
mb_internal_encoding('UTF-8');
$now = time();
chdir(DIRS_PATH);
if ($anime_dirs = glob('*')) {
  if ($curl = curl_init()) {
    if (is_file(SCRIPT_PATH))
      $db = json_decode(file_get_contents(SCRIPT_PATH), true);
    $html = '';
    foreach ($anime_dirs as $dir) {
      if (!isset($db[$dir])) {
        $db[$dir] = array('i' => kg_search($dir), 'last_ok' => $now + 3600);
        if (!$db[$dir]['i'])
          continue;
        $db[$dir]['l'] = kg_get_links($db[$dir]['i']);
      }
      if ($db[$dir]['last_ok'] < $now)
        $db[$dir]['l'] = kg_get_links($db[$dir]['i']);
      $last_ser = 0;
      $dl_files = glob($dir.'/*.ass');
      if ($dl_files) {
        $dir_len = strlen($dir) + 1;
        foreach ($dl_files as $file)
          if (preg_match('/ \- (\d+)/', substr($file, $dir_len, -4), $kg_series))
            $last_ser = max($last_ser, intval($kg_series[1]));
      }
      foreach ($db[$dir]['l'] as $subs) {
        if (preg_match('/\((\d+\-)?(\d+)\)/', $subs[1], $series) || preg_match('/(\d+\-)?(\d+)/', $subs[1], $series))
          if ($series[2] > $last_ser) {
            kg_dl_zip($subs[0], $dir);
            $db[$dir]['last_ok'] = $now + (3600 * 24 * 5);
          }
      }
    }
    curl_close($curl);
    if ($html) {
      $html_fl =  __DIR__.'/'.$now.'-new.html';
      file_put_contents($html_fl, '<html><head><meta charset="utf-8"><link rel="stylesheet" href="style.css"><script src="script.js"></script></head><body>'.$html.'</body></html>');
      `START $html_fl`;
      usleep(2000000);
      unlink($html_fl);
    }
  }
}
file_put_contents(SCRIPT_PATH, json_encode($db, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
function kg_search($q){
  $anime_search_raw = kg_get_url('search.php', array('query' => $q));
  if (preg_match('~href="base\.php\?id=(\d+)"~', $anime_search_raw, $anime_search))
    return $anime_search[1];
}
function kg_get_links($kg_id){
  $subs_list_raw = kg_get_url('base.php?id='.$kg_id);
  if (!preg_match_all('~"srt" value="(\d+)".*<b>(.*)</b>.*(\d{2}\.\d{2}\.\d{2}).*\?au=(\d+)"><b>(.*)</b>~sU', $subs_list_raw, $subs_list)) {
    exit("! id: {$kg_id}\n");
  }
  $links = array();
  foreach ($subs_list[2] as $k => $subs_name)
    $links[] = array($subs_list[1][$k], mb_convert_encoding($subs_name, 'UTF-8', 'CP1251'), $subs_list[4][$k], mb_convert_encoding($subs_list[5][$k], 'UTF-8', 'CP1251'), date_timestamp_get(date_create_from_format('d.m.y', $subs_list[3][$k])));
  return $links;
}
function kg_get_url($url, $post = array()){
  global $curl;
  $opts = array(
    CURLOPT_URL => KG_URL_PRFX.$url,
    CURLOPT_RETURNTRANSFER => true);
  if ($post) {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_REFERER] = KG_URL_PRFX;
    $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
  }
  curl_setopt_array($curl, $opts);
  $out = curl_exec($curl);
  usleep(rand(30, 50)*100000);
  return $out;
}
function kg_dl_zip($zip_id, $dir) {
  if (($curl_handle = curl_init()) && $file_handle = fopen("$dir/$zip_id.tmp", 'w')) {
    curl_setopt_array($curl_handle, array(
      CURLOPT_URL => KG_URL_PRFX.'base.php',
      CURLOPT_ENCODING => '',
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => array('srt' => $zip_id),
      CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:29.0) Gecko/20100101 Firefox/29.0',
      CURLOPT_FILE => $file_handle));
    curl_exec($curl_handle);
    $info = curl_getinfo($curl_handle);
    curl_close($curl_handle);
    fclose($file_handle);
    if (preg_match('/name="([\w\.\[\]\(\)\-_]+)"/', $info['content_type'], $name)) {
      rename("$dir/$zip_id.tmp", $dir.'/'.$name[1]);
      if (substr($name[1], -3) == 'zip') {
        $zip = zip_open($dir.'/'.$name[1]);
        if (is_resource($zip)) {
          $dir = '.';
          while (is_resource($entry = zip_read($zip))) {
            $is_file = true;
            $name = zip_entry_name($entry);
            $name_parts = explode('/', $name);
            if (count($name_parts) > 1) {
              $path = array_pop($name_parts);
              $is_file = !empty($path);
              $path = $dir;
              foreach ($name_parts as $part) {
                $path .= '/'.$part;
                if (!is_dir($path))
                  mkdir($path);
              }
            }
            if ($is_file)
              file_put_contents($dir.'/'.$name, zip_entry_read($entry, zip_entry_filesize($entry)));
          }
          zip_close($zip);
          unlink($dir.'/'.$name[1]);
        }
      }
    }
  }
}
