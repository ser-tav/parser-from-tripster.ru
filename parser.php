<?php
set_time_limit(100);

require_once('../wp-load.php');
require_once('../wp-admin/includes/taxonomy.php');

start_pars();

//Основная функция парсера
function start_pars()
{

    $ex_type = array(
        'private' => 'индивидуальная',
        'group' => 'групповая'
    );

    global $wpdb;

    $import_count = "{$_SERVER['DOCUMENT_ROOT']}/parser/import_count.txt";
    $import_date = "{$_SERVER['DOCUMENT_ROOT']}/parser/import_date.txt";

    //Запускаться раз в 7 дней
    if (file_exists($import_date)) {
        $date_run = file($import_date);
        $date_run = $date_run[0];
        $date_result = strtotime(date('Y-m-d')) - strtotime($date_run);
        $date_result = abs($date_result);
        $date_result = $date_result / (3600 * 24);
        if ($date_result < 7) return;
    }

    //Начальные значения каунтов
    $like = '';
    $url = '';
    $draft_result = 0;
    if (file_exists($import_count)) {
        $count_ar = file($import_count);
        $count_ar = $count_ar[0];
        $count_ar = explode('::', $count_ar);
        if (!empty($count_ar[0])) {
            $like = " WHERE `id`>={$count_ar[0]}";
        }
        if (!empty($count_ar[2])) {
            $url = $count_ar[2];
        }
    }

    //Формируем xml ссылку
    $results = $wpdb->get_results("SELECT * FROM `city_result`$like LIMIT 2");
    //city__name_ru фильтр по названию города на русском
    if (empty($url)) {
        $draft_result = 1;
        if (!empty($results[0]->res_name)) {
            $url = "https://experience.tripster.ru/api/experiences/?city__iata=" . trim($results[0]->res_name) . "&detailed=true&format=json&page_size=15";
        } else {
            $url = "https://experience.tripster.ru/api/experiences/?city__name_ru=" . trim($results[0]->name) . "&detailed=true&format=json&page_size=15";
        }
    }
    //updated_after=YYYY-MM-DD или YYYY-MM-DD HH:MM:SS будут возвращены экскурсии, которые изменились с этого времени

    //Погнали парсить
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT,
        "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $excursions = curl_exec($ch);
    curl_close($ch);
    $excursions = json_decode($excursions);

    if ($excursions->results) {
        //ID рубрики "Экскурсии"
        $main_cat = 12;
        //Город
        $city = $excursions->results[0]->city;
        echo "Текущий город $city->name_ru <br/>";
        //Страна
        $country = $city->country;

        //Сперва добавим страну, если ее еще нет в базе
        $parent_id = result_category($country->name_ru, 1);
        if (!$parent_id) {
            $category = array();
            $category['cat_name'] = $country->name_ru;
            $category['category_nicename'] = translit($country->name_ru);
            $category['category_parent'] = $main_cat;
            if ($parent_id = wp_insert_category($category)) {
                //Добавляем страны в Предложном падеже
                add_option('category_' . $parent_id . '_city_cat', city_padej($country->name_ru));
                //Тип рубрики - страна
                add_option('category_' . $parent_id . '_type_cat', 1);
                echo 'Добавлена страна: ' . $country->name_ru . '<br/>';
            }
        }

        //Добавим город, если его еще нет в базе
        $cat_id = result_category($city->name_ru, 2);
        if (!$cat_id) {
            $category = array();
            $category['cat_name'] = $city->name_ru;
            $category['category_nicename'] = translit($city->name_ru);
            $category['category_parent'] = $parent_id;
            if ($cat_id = wp_insert_category($category)) {
                echo 'Добавлен город: ' . $city->name_ru . '<br/>';
                //Добавляем город в Предложном падеже
                add_option('category_' . $cat_id . '_city_cat', city_padej($city->name_ru));
                //Добавляем картинку к категории
                wp_sideload_image(array('post_id' => $cat_id, 'type' => 'cat'), $city->image->thumbnail,
                    $city->name_ru);
                //Тип рубрики - город
                add_option('category_' . $cat_id . '_type_cat', 2);
            }
        }

        //Выставить всем экскурсиям города статус "Черновик"
        if (!empty($draft_result)) {
            echo '<b>ВСЕ В ЧЕРНОВИК</b><br/>';
            $post_city = $wpdb->get_results("SELECT `object_id` FROM `wp_term_relationships` WHERE `term_taxonomy_id`=$cat_id");
            foreach ($post_city as $pc) {
                $pc_update = array();
                $pc_update['ID'] = $pc->object_id;
                $pc_update['post_status'] = 'draft';
                wp_update_post($pc_update);
            }
        }

        //Добавим все экскурсии данного города
        $redirect_global = 0;
        echo '<b>СПИСОК ЭКСКУРСИЙ</b><br/>';
        foreach ($excursions->results as $i => $excursion) {
            $post_data = array();

            $update_id = $excursion->id;
            //Првоеряем есть ли в базе такой
            $post_result = $wpdb->get_results("SELECT `post_id` FROM `wp_postmeta` WHERE `meta_key`='update_id' AND `meta_value`='$update_id'");

            $post_data['post_title'] = $excursion->title;
            $post_data['post_status'] = 'publish';
            $post_data['post_content'] = clear_description($excursion->description);
            $post_data['meta_input']['description2'] = clear_description($excursion->tagline);
            $post_data['meta_input']['rating'] = $excursion->rating;
            $post_data['meta_input']['g_comment_count'] = $excursion->review_count;
            $post_data['meta_input']['duration'] = $excursion->duration;
            $post_data['meta_input']['full_price'] = '';//$excursion->price;
            $post_data['meta_input']['minimal_full_price'] = '';//$excursion->minimal_full_price;
            $post_data['meta_input']['full_price_local'] = '';//$excursion->full_price_local;
            $post_data['meta_input']['minimal_full_price_local'] = $excursion->price->value;
            $post_data['meta_input']['currency'] = $excursion->price->currency;
            $post_data['meta_input']['price_for'] = $excursion->price->unit_string;
            $post_data['meta_input']['ex_type'] = $ex_type[$excursion->type];

            $post_data['meta_input']['guide'] = $excursion->guide->first_name;
            $post_data['meta_input']['guide_url'] = $excursion->guide->url;
            $post_data['meta_input']['partner_page'] = $excursion->url;

            if (empty($post_result)) {//Добавление нового
                $terms = terms($excursion->tags, $cat_id);
                $terms['cat'][] = $cat_id;

                $post_data['post_type'] = 'post';
                $post_data['post_category'] = $terms['cat'];
                $post_data['post_name'] = translit($excursion->title);

                $post_data['meta_input']['city'] = $city->name_ru;
                $post_data['meta_input']['country'] = $country->name_ru;
                $post_data['meta_input']['update_id'] = $update_id;

                //Добавляем экскурсию
                $post_id = wp_insert_post($post_data);
                if ($post_id) {
                    echo 'Добавлена экскурсия: ' . $excursion->title . '<br/>';
                    wp_sideload_image(array('post_id' => $post_id, 'type' => 'post', 'fild_name' => '_thumbnail_id'),
                        $excursion->photos[0]->medium, $excursion->title);
                    wp_sideload_image(array('post_id' => $post_id, 'type' => 'post', 'fild_name' => 'img_2'),
                        $excursion->guide->avatar->medium, $excursion->title);
                } else {
                    echo 'Ошибка добавления экскурсии: ' . $excursion->title . '<br/>';
                }
            } else {//Апдейт текущего
                $post_data['ID'] = $post_result[0]->post_id;
                foreach ($post_data['meta_input'] as $i => $mt) {
                    if (empty($mt)) {
                        delete_post_meta($post_data['ID'], $i);
                    } else {
                        update_post_meta($post_data['ID'], $i, $mt);
                    }
                    unset($post_data['meta_input'][$i]);
                }
                if (wp_update_post($post_data)) {
                    echo 'Экскурсия обновлена: ' . $excursion->title . '<br/>';
                } else {
                    echo 'Ошибка обновления экскурсии: ' . $excursion->title . '<br/>';
                }
            }
        }
    }

    //Если есть некст записи записываем в файл
    if ($excursions->next) {
        $file_text = $results[0]->id . '::' . $results[0]->name . '::' . $excursions->next;
        $file = fopen($import_count, "w+");
        fputs($file, $file_text);
        fclose($file);
        //Если есть некст город записываем в файл
    } else if (!empty($results[1])) {
        $file_text = $results[1]->id . '::' . $results[1]->name;
        $file = fopen($import_count, "w+");
        fputs($file, $file_text);
        fclose($file);
    } else {
        //Если нефига нету делим каунтер файл и записываем дату
        unlink($import_count);
        $file_text = date('Y-m-d');
        $file = fopen($import_date, "w+");
        fputs($file, $file_text);
        fclose($file);
        echo '<b>Парсинг окончен</b>';
    }
}

//Залив городов с CSV файла
function csv_zaliv()
{
    global $wpdb;

    //Файл для сопоставления категорий
    $handle = fopen("parser/city.csv", "r");
    $array_csv = array();
    while (($line = fgetcsv($handle, 0, ";")) !== FALSE) {
        $wpdb->insert('city_result', array('name' => $line[0], 'res_name' => $line[1], 'res_id' => $line[2]));
    }
    fclose($handle);
}

//Проверяем есть ли такой объект в базе
function result_category($name, $type)
{

    $results = get_terms('category', array(
        'hide_empty' => false,
        'search' => $name
    ));
    if ($results) {
        foreach ($results as $result) {
            if (get_field("type_cat", $result) == $type) {
                return $result->term_id;
            }
        }
    }

    return false;
}

//Функция загрузки изображений
function wp_sideload_image($post = array(), $file, $desc = null, $debug = false)
{
    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    // Загружаем файл во временную директорию
    $tmp = download_url($file);

    // Устанавливаем переменные для размещения
    preg_match('/[^\?]+\.(jpg|jpe|jpeg|gif|png)/i', $file, $matches);
    $file_array['name'] = basename($matches[0]);
    $file_array['tmp_name'] = $tmp;

    // Удаляем временный файл, при ошибке
    if (is_wp_error($tmp)) {
        @unlink($file_array['tmp_name']);
        $file_array['tmp_name'] = '';
        if ($debug) echo 'Ошибка нет временного файла! <br />';
    }

    // проверки при дебаге
    if ($debug) {
        echo 'File array: <br />';
        print_r($file_array);
        echo '<br /> Post id: ' . $post['post_id'] . '<br />';
    }

    //Загрузка файла
    $id = media_handle_sideload($file_array, $post['post_id'], $desc);
    // Проверяем работу функции
    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        print_r($id->get_error_messages());
    } else {
        if ($post['type'] == 'post') {//Если принадлежин посту
            update_post_meta($post['post_id'], $post['fild_name'], $id);
        } else if ($post['type'] == 'cat') {//Если принадлежин категории
            add_option('category_' . $post['post_id'] . '_imgcat1', $id);
        }
    }
    // удалим временный файл
    @unlink($file_array['tmp_name']);

    return $id;
}

//Добавление меток
function terms($terms, $parent)
{
    $return['terms'] = '';
    $return['cat'] = array();
    //Первую метку пропускаем
    foreach ($terms as $i => $item) {

        $item = $item->name;

        //Смотрим есть ли в базе у данного города такая категория
        $rubrika = get_terms('category', array(
            'parent' => $parent,
            'hide_empty' => false,
            'search' => $item
        ));
        if ($rubrika) {
            //Переносим ID
            $return['cat'][] = $rubrika[0]->term_id;
        } else {
            $category = array();
            $category['cat_name'] = $item;
            $category['category_nicename'] = translit($item);
            $category['category_parent'] = $parent;
            if ($parent_id = wp_insert_category($category)) {
                //Тип категории - метка
                add_option('category_' . $parent_id . '_type_cat', 3);
                $return['cat'][] = $parent_id;
            }
        }
    }
    //Возвращаем список добавленных меток
    return $return;
}

//Склонение города
function city_padej($city)
{
    //http://www.api.morpher.ru/ws3/
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://ws3.morpher.ru/russian/declension?s=" . $city . "&format=json");
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT,
        "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    $padej = curl_exec($ch);
    curl_close($ch);
    $padej = json_decode($padej);
    $padej = (array)$padej;

    return $padej['П'];
}

//Очистка описания
function clear_description($text)
{
    $clear = array(
        '🍺',
        '🍫',
        '🥐'
    );
    return str_replace($clear, '', $text);
}

//Функция транслита
function translit($s)
{
    $s = (string)$s;
    $s = strip_tags($s);
    $s = str_replace(array("\n", "\r"), " ", $s);
    $s = preg_replace("/\s+/", ' ', $s);
    $s = trim($s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    $s = strtr($s,
        array('а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'j', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya', 'ъ' => '', 'ь' => ''));
    $s = preg_replace("/[^0-9a-z-_ ]/i", "", $s);
    $s = str_replace(" ", "-", $s);
    return $s;
}

?>