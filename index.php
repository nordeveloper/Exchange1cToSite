<?php

require("vendor/autoload.php");

use Zenwalker\CommerceML\CommerceML;

session_start();

/**
 * Начало сеанса
 * Выгрузка данных начинается с того, что система "1С:Предприятие" отправляет http-запрос следующего вида:
 * http://<сайт>/<путь> /1c-exchange?type=catalog&mode=checkauth.
 * В ответ система управления сайтом передает системе «1С:Предприятие» три строки (используется разделитель строк "\n"):
 * - слово "success";
 * - имя Cookie;
 * - значение Cookie.
 * Примечание. Все последующие запросы к системе управления сайтом со стороны "1С:Предприятия" содержат в заголовке запроса имя и значение Cookie.
 */

/**
 * Запрос параметров от сайта
 * Далее следует запрос следующего вида:
 * http://<сайт>/<путь> /1c-exchange?type=catalog&mode=init
 * В ответ система управления сайтом передает две строки:
 * 1. zip=yes, если сервер поддерживает обмен
 * в zip-формате -  в этом случае на следующем шаге файлы должны быть упакованы в zip-формате
 * или zip=no - в этом случае на следующем шаге файлы не упаковываются и передаются каждый по отдельности.
 * 2. file_limit=<число>, где <число> - максимально допустимый размер файла в байтах для передачи за один запрос.
 * Если системе "1С:Предприятие" понадобится передать файл большего размера, его следует разделить на фрагменты.
 */

/**
 * C. Выгрузка на сайт файлов обмена
 * Затем «1С: Предприятие» запросами с параметрами вида
 * http://<сайт>/<путь> /1c_exchange.php? type=catalog& mode=file& filename=<имя файла>
 * выгружает на сайт файлы обмена в формате CommerceML 2, посылая содержимое файла или его части в виде POST.
 * В случае успешной записи файла система управления сайтом выдает строку «success».
 */


/**
 * На последнем шаге по запросу из "1С:Предприятия" производится пошаговая загрузка данных по запросу
 * с параметрами вида http://<сайт>/<путь> /1c_exchange.php?type=catalog&mode=import&filename=<имя файла>
 * Во время загрузки система управления сайтом может отвечать в одном из следующих вариантов.
 * 1. Если в первой строке содержится слово "progress" - это означает необходимость послать тот же запрос еще раз.
 * В этом случае во второй строке будет возвращен текущий статус обработки, объем  загруженных данных, статус импорта и т.д.
 * 2. Если в ответ передается строка со словом "success", то это будет означать сообщение об успешном окончании
 * обработки файла.
 * Примечание. Если в ходе какого-либо запроса произошла ошибка, то в первой строке ответа системы управления
 * сайтом будет содержаться слово "failure", а в следующих строках - описание ошибки, произошедшей в процессе
 * обработки запроса.
 * Если произошла необрабатываемая ошибка уровня ядра продукта или sql-запроса, то будет возвращен html-код.
 */


$exchangePath = $_SERVER['DOCUMENT_ROOT'].'exchange1c/';

function d($var){
    echo '<pre>';
    print_r($var);
    echo '</pre>';
};

function ModeCheckAuth($request, $log=false){
    if($request['mode']=='checkauth'){
        $session = session_id();
        echo "success\n";
        echo session_name()."\n";
        echo session_id() ."\n";
        echo "timestamp=".time()."\n";
        //echo "Cookie=".$session."\n";
        //echo "sessid=".$session;
        if($log){
            file_put_contents(__DIR__.'/checkauthLog.txt', print_r($request,true), FILE_APPEND);
        }
    }
}


function Init($request, $log=false){

    if( $request['type']=='catalog' and $request['mode']=='init'){
        $_SESSION["IMPORT"]['zipEnable'] = function_exists('zip_open');
        $response = 'zip='.($_SESSION["IMPORT"]['zipEnable'] ? 'yes' : 'no')."\n";
        $response .= 'file_limit=100000000';
        if($log){
            file_put_contents(__DIR__.'/initLog.txt', print_r($request,true), FILE_APPEND);
            file_put_contents(__DIR__.'/initLog.txt', print_r($_SESSION,true), FILE_APPEND);
        }

        echo $response;
    }
}



function Import($request, $exchangePath, $log=false){

    if($request['mode']=='file' and !empty($request['filename']) ){

        clearImportDirectory($exchangePath);

        $filePath = $exchangePath.$request['filename'];

        if (!is_dir($exchangePath)) {
            mkdir($exchangePath, 0755, true);
        }

        $f = fopen($filePath, 'a+');
        fwrite($f, file_get_contents('php://input'));
        fclose($f);

        if( !empty($_SESSION["IMPORT"]['zipEnable']) ){
            $_SESSION["IMPORT"]["zipfile"] = $request['filename'];
        }

        echo "success";

        if($log){
            file_put_contents(__DIR__.'/mode-fileLog.txt', print_r($request,true), FILE_APPEND);
            file_put_contents(__DIR__.'/mode-fileLog.txt', print_r($_SESSION,true), FILE_APPEND);
        }
    }


    if( $request['type']== 'catalog' and $request['mode']=='import' and !empty($_SESSION["IMPORT"]["zipfile"]) ){

        $filePath = $exchangePath.$_SESSION["IMPORT"]["zipfile"];

        if( file_exists($filePath) ){
            $zip = new \ZipArchive();
            $zip->open($filePath);
            $z = $zip->extractTo($exchangePath);
            $zip->close();
            @unlink($filePath);
            echo "success";
        }

        CatalogSave($exchangePath.$request['filename']);

        if($log){
            file_put_contents(__DIR__.'/modeImportLog.txt', print_r($request,true), FILE_APPEND);
            file_put_contents(__DIR__.'/modeImportLog.txt', print_r($_SESSION,true), FILE_APPEND);
            file_put_contents(__DIR__.'/modeImportLog.txt', print_r($filePath,true), FILE_APPEND);
        }
    }

}


function CatalogSave($filePath){

    $cml = new CommerceML();

    if(file_exists($filePath)){
        $cml->loadImportXml($filePath);
        //$commerce->loadOffersXml($filePath);

        foreach ($cml->classifier->groups as $group){
            foreach ($group->getChildren() as $children){
                file_put_contents(__DIR__.'/CatalogSaveGroupsLog.txt', $children->name."\n", FILE_APPEND);
                foreach ($children->getChildren() as $child){
                    file_put_contents(__DIR__.'/CatalogSaveGroupsLog.txt', "\t".$child->name."\n", FILE_APPEND);
                }

            }

        }

        //$cml->catalog->

        foreach ($cml->catalog->products as $product){

           // $product->name название товара (Товары->Товар->Наименование)
            file_put_contents(__DIR__.'/CatalogSave-ProductLog.txt', 'Section:'.$product->getGroup()->name.' Product:'.$product->name."\n", FILE_APPEND);

            if(!empty($product->offers)){
                foreach ($product->offers as $offer){
                    // Выводим название предложения (Предложения->Предложение->Наименование)
                    // Выводим первую цену предложения (Предложения->Предложение->Цены->Цена->ЦенаЗаЕдиницу)
                    file_put_contents(__DIR__.'/logCatalogSaveOffers.txt', $offer->name.' '.$offer->prices[0]->cost.' '."\n", FILE_APPEND);
                }
            }
        }
    }

}


function clearImportDirectory($path)
{
    if(!empty($path)){
        $tmp_files = glob( $path. DIRECTORY_SEPARATOR . '*.*');
        if (is_array($tmp_files)) {
            foreach ($tmp_files as $v) {
                @unlink($v);
            }
        }
    }
}

ModeCheckAuth($_REQUEST);

Init($_REQUEST);

Import($_REQUEST, $exchangePath);