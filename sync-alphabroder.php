<?php
/*
 * Directory for Synchronization Files
 */
define('SA_SYNC_DIR', substr($_SERVER['SCRIPT_FILENAME'], 0, strrpos($_SERVER['SCRIPT_FILENAME'], '/') + 1) . 'wp-content/themes/scwd-wp5default/inc/sync/');
/* FTP Server */
define('SA_FTP_SERVER','ftp.appareldownload.com');
/*
 *Alphabroder Customer Account Personal FTP Server
 */
define('SA_FTP_USERNAME', 'efu3428613');
define('SA_FTP_PASSWORD', 'WarmFall25!');
define('SA_CATALOG','AllDBInfoALP_Prod.txt');
define('SA_INVENTORY','3428613-daily-alp.txt');
define('SA_STOCK','inventory-v4-alp.txt');
/*
 * Site's copy of the files from the FTP Server
 */
define('SA_LOCAL_CATALOG', SA_SYNC_DIR.'AllDBInfoALP_Prod.txt');
define('SA_LOCAL_INVENTORY', SA_SYNC_DIR.'inventory.txt');
define('SA_LOCAL_STOCK', SA_SYNC_DIR.'stock.txt');
define('SA_INVENTORY_CSV', SA_SYNC_DIR.'inventory.csv');
define('SA_CATALOG_CSV', SA_SYNC_DIR.'AllDBInfo.csv');
define('SA_STOCK_CSV', SA_SYNC_DIR.'stock.csv');
/*
 * Woocommerce REST API credentials
 */
define('SA_CONSUMER_KEY', 'ck_58b05a657e913d0bc68ab99635d4c91c1d7301be');
define('SA_SECRET_KEY', 'cs_9c7bcd97c1991a6816cacf49f83888ee579cd7b3');

/*
 * Shortcode / Function for Alphabroder Synchronization
 */
function sync_alphabroder(){
    /*Delete and Recreate Synchronization Files*/
    $file_array = array(
        SA_LOCAL_CATALOG,
        SA_LOCAL_INVENTORY,
        SA_LOCAL_STOCK,
        SA_CATALOG_CSV,
        SA_INVENTORY_CSV,
        SA_STOCK_CSV,
    );
    foreach($file_array as $file){
        if (file_exists($file)) {
            unlink($file);
        }
        $temp = fopen($file, "w");
        fclose($temp);
    }
    /*Download Synchronization Files*/
    if (sa_ftp_download()) {
        // Transfer to csv for logging
        sa_convert_txt(SA_LOCAL_INVENTORY, SA_INVENTORY_CSV, false);
        sa_convert_txt(SA_LOCAL_CATALOG, SA_CATALOG_CSV, false);
        sa_convert_txt(SA_LOCAL_STOCK, SA_STOCK_CSV, false);
        // Import Products
        $products = sa_map_headers(SA_CATALOG_CSV, 500);
        $item_prices = sa_get_inventory_prices();
        $item_stock = sa_get_inventory_stock();
        sa_import_products($products, $item_prices, $item_stock);
        echo "Transfers successful";
    } else {
        echo "There was a problem\n";
    }
}
add_shortcode('sync_alphabroder','sync_alphabroder');
/**
 * Connect to FTP Server
 *
 * @param bool $catalog | select catalog file or inventory file
 */
function sa_ftp_download(){
    /*ftp connect*/
    $conn_id = ftp_ssl_connect(SA_FTP_SERVER);
    // login with username and password
    $login_result = ftp_login($conn_id, SA_FTP_USERNAME, SA_FTP_PASSWORD);
    // try to download server_file and save to local_file
    ftp_pasv($conn_id, true);
    /*Write to file*/
    $catalog = ftp_get($conn_id, SA_LOCAL_CATALOG, SA_CATALOG, FTP_BINARY);
    $inventory = ftp_get($conn_id, SA_LOCAL_INVENTORY, SA_INVENTORY, FTP_BINARY);
    $stock = ftp_get($conn_id, SA_LOCAL_STOCK, SA_STOCK, FTP_BINARY);
    // check if all transfers are successful
    $return = $catalog && $inventory && $stock;
    // close the connection
    ftp_close($conn_id);
    return $return;
}
/**
 * Convert txt file downloaded to csv
 *
 * @param string $file | absolute file path of source file
 * @param string $write_to_file | absolute file path of destination file
 * @param array $return_array | Determines the return value of the function
 */
function sa_convert_txt($file = null, $write_to_file = null, $return_array = true){
    if (empty($file) || empty($write_to_file)) return;
    // replace commas preemtively
    $temp = trim(file_get_contents($file));
    if ($file == SA_LOCAL_CATALOG) {
        $temp = trim(str_replace(',','$', file_get_contents($file)));
    }
    // Set csv delimiter and trim quotes
    $formatted = str_replace('^',',', $temp);
    $inventory_file = str_replace('"', '', $formatted);
    // Convert to array
    $inventory_file_array = explode("\n", $inventory_file);
    $file_array = array();
    foreach($inventory_file_array as $line) {
        $line_array = explode(",", $line);
        array_push($file_array, $line_array);
    }
    if (!$return_array) {
        // put array data to csv file
        $inventory_csv = fopen($write_to_file, 'w');
        foreach ($file_array as $fields) {
            fputcsv($inventory_csv, $fields);
        }
        fclose($inventory_csv);
        return;
    } else {
        return $file_array;
    }
}
/**
 * Map CSV Headers as Key names on associative array
 *
 * @param string $file | absolute file path string
 * @param int $limit | result count | default value = 0 to show all
 */
function sa_map_headers($file = null, $max = -1){
    if (empty($file)) return;
    $array = $fields = array(); $i = 0;
    $handle = fopen($file, "r");

    if ($handle) {
        while (($row = fgetcsv($handle)) !== false) {
            if (empty($fields)) {
                $fields = $row;
                continue;
            }
            foreach ($row as $k=>$value) {
                $array[$i][$fields[$k]] = $value;
            }
            if ($max == $i) break;
            $i++;
        }
        // if (!feof($handle)) {
        //     echo "Error: unexpected fgets() fail\n";
        // }
        fclose($handle);
    }
    return $array;
}
/**
 * Import products programatically
 *
 * @param array $product_array | Contains all products to import.
 * @param array $item_prices | product variation prices.
 * @param array $item_stock | product variation stock qty.
 */
function sa_import_products($product_array, $item_prices, $item_stock){
    /* variable reference
        sku         = 'Style'
        title       = 'Short Description'
        content     = 'Full Feature Description'
        category    = array( 'Category' , 'Subcategory' )
        brand/mill  = 'Mill Name'
    */
    if (!empty($product_array)){

        $attributes = sa_get_global_attributes();

        foreach ($product_array as $product){
            // define variables
            $sku = $product['Style'];
            $brand = get_term_by('name', $product['Mill Name'], 'product_brands');
            $title = sa_filter_text($product['Short Description']);
            // Get Category/ies
            $category = array();
            $cat = explode('|', $product['Category']);
            $cat_array = array_map('trim', $cat);

            foreach ($cat_array as $cat_item) {
                $term = get_term_by('name', $cat_item, 'product_cat');
                if ($term) $category[] = $term->term_id;
            }
            // Format content to html
            $content = sa_product_content_format($product['Full Feature Description']);
            // get product id by sku
            $product_id = wc_get_product_id_by_sku($sku);
            //no product exist with the given SKU so create one
            if (!$product_id) {
                // Add Variable Product
                $prod = new WC_Product_Variable();
                $prod->set_sku($sku);
            } else {
                // initialize existing variable product
                $prod = new WC_Product_Variable($product_id);
            }

            $prod->set_name($title);
            $prod->set_description($content);
            $prod->set_category_ids($category);            
            // set product brand
            if ($brand) wp_set_object_terms($product_id, $brand->term_id, 'product_brands');

            // add product variation
            $color = $product['Color Name'];
            $size = $product['Size'];
            $item_number = $product['Item Number'];
            $price = isset($item_prices[$item_number]) ? $item_prices[$item_number] : 0;
            $stock_qty = isset($item_stock[$item_number]) ? $item_stock[$item_number] : 0;
            $variation_data =  array(
                'attributes' => array(
                    'size'  => $size,
                    'color' => $color,
                ),
                'sku'           => '',
                'regular_price' => $price,
                'stock_qty'     => $stock_qty,
            );
            create_product_variation($prod->save(), $variation_data);

            // set product attributes
            $prod->set_attributes($attributes);
            $product_id = $prod->save();
        }
    }
}
/**
 * Create a product variation for a defined variable product ID.
 *
 * @since 3.0.0
 * @param int   $product_id | Post ID of the product parent variable product.
 * @param array $variation_data | The data to insert in the product.
 */
function create_product_variation( $product_id, $variation_data ){
    // Get the Variable product object (parent)
    $product = wc_get_product($product_id);
    $variation_post = array(
        'post_title'  => $product->get_name(),
        'post_name'   => 'product-'.$product_id.'-variation',
        'post_status' => 'publish',
        'post_parent' => $product_id,
        'post_type'   => 'product_variation',
        'guid'        => $product->get_permalink()
    );
    // Creating the product variation
    $variation_id = wp_insert_post( $variation_post );
    // Get an instance of the WC_Product_Variation object
    $variation = new WC_Product_Variation( $variation_id );
    // Iterating through the variations attributes
    foreach ($variation_data['attributes'] as $attribute => $term_name )
    {
        $taxonomy = 'pa_'.$attribute; // The attribute taxonomy
        // If taxonomy doesn't exists we create it (Thanks to Carl F. Corneil)
        if( ! taxonomy_exists( $taxonomy ) ){
            register_taxonomy($taxonomy,'product_variation',array('hierarchical' => false,'label' => ucfirst( $attribute ),'query_var' => true,'rewrite' => array( 'slug' => sanitize_title($attribute) )));
        }
        // Check if the Term name exist and if not we create it.
        if( ! term_exists( $term_name, $taxonomy ) )
            wp_insert_term( $term_name, $taxonomy ); // Create the term
        $term_slug = get_term_by('name', $term_name, $taxonomy )->slug; // Get the term slug
        // Get the post Terms names from the parent variable product.
        $post_term_names =  wp_get_post_terms( $product_id, $taxonomy, array('fields' => 'names') );
        // Check if the post term exist and if not we set it in the parent variable product.
        if( ! in_array( $term_name, $post_term_names ) )
            wp_set_post_terms( $product_id, $term_name, $taxonomy, true );
        // Set/save the attribute data in the product variation
        update_post_meta( $variation_id, 'attribute_'.$taxonomy, $term_slug );
    }
    ## Set/save all other data
    // SKU
    if( ! empty( $variation_data['sku'] ) )
        $variation->set_sku( $variation_data['sku'] );
    // Prices
    if( empty( $variation_data['sale_price'] ) ){
        $variation->set_price( $variation_data['regular_price'] );
    } else {
        $variation->set_price( $variation_data['sale_price'] );
        $variation->set_sale_price( $variation_data['sale_price'] );
    }
    $variation->set_regular_price( $variation_data['regular_price'] );
    // Stock
    if( ! empty($variation_data['stock_qty']) ){
        $variation->set_stock_quantity( $variation_data['stock_qty'] );
        $variation->set_manage_stock(true);
        $variation->set_stock_status('');
    } else {
        $variation->set_manage_stock(false);
    }
    $variation->set_weight(''); // weight (reseting)
    $variation->save(); // Save the data
}

/**
 * Modify Product's Full Feature Description
 * 
 * @param string $content | modify this text
 */
function sa_product_content_format($content = null){
    if (empty($content)) return "<p>No description</p>";
    /*$full_desc = str_replace('$', ',', $content);
    $full_desc = rtrim($full_desc, ';');
    $full_desc = explode(';', $full_desc);
    return sa_to_html_list(array_splice($full_desc, 0, 3),'Fabric:') . sa_to_html_list(array_splice($full_desc, 3), 'Feature:');*/
    $full_desc = str_replace('$', ',', $content);
    return sa_to_html_list(explode(';', $full_desc), 'Fabric &amp; Features');
}
/**
 * Convert array to html list with heading 3 title
 * 
 * @param array $array | contains the collection of string to convert
 * @param string $title | name or title of the list
 */
function sa_to_html_list($array = null, $title = ''){
    if (empty($array) && !is_array($array)) return;
    $string = !empty($title) ? '<h3>'.$title.'</h3><ul><li>' : '<ul><li>';
    foreach ($array as $list_item) {
        $string .= sa_filter_text($list_item) . "</li><li>";
    }
    $string = substr($string, 0, -4) . "</ul>";
    return $string;
}
/**
 * Format text
 * 
 * @param string $string | format this text
 */
function sa_filter_text($string = ""){
    // convert curly quotes
    $search = array(chr(145), 
                    chr(146), 
                    chr(147), 
                    chr(148), 
                    chr(151)); 
    $replace = array("'", 
                     "'", 
                     '"', 
                     '"', 
                     '-'); 
    $string = str_replace($search, $replace, $string);
    // remove foreign encoded characters
    $string = preg_replace('/[\x00-\x1F\x7F-\xFF]/', ' ', $string);
    // remove whitespaces
    return trim($string);
}
/**
 * Get Total Stock Quantity from CSV
 * 
 */
function sa_get_inventory_stock(){
    $inventory = sa_map_headers(SA_STOCK_CSV);
    $item_qty = array(); $i = 0;
    foreach ($inventory as $key => $value) {
        // get total qty from all warehouses
        // $total_qty = (int) $value['CC'] + (int) $value['FO'] + (int) $value['KC'] + (int) $value['MA'] + (int) $value['PH'] + (int) $value['TD'] + (int) $value['CN'] + (int) $value['GD'];
        $warehouses = array_slice($inventory[$key], -8, 8);
        $total_qty = array_sum($warehouses);
        $item_qty[$inventory[$i]['Item Number']] = $total_qty;
        $i++;
    }
    return $item_qty;
}
 /**
 * Get Product Prices from CSV
 * 
 */
function sa_get_inventory_prices(){
    // Get Inventory Prices and Total Stock Quantity
    $inventory = sa_map_headers(SA_INVENTORY_CSV);
    $item_prices = array(); $i = 0;
    foreach ($inventory as $key => $value) {
        $item_prices[$inventory[$i]['Item Number']] = $value['Price'];
        $i++;
    }
    return $item_prices;
}
/**
 * Get Global Attributes
 * 
 */
function sa_get_global_attributes(){
    $attribute_objects = wc_get_attribute_taxonomy_ids();
    $product_attributes = array(); $i = 0;

    foreach ($attribute_objects as $attribute => $att_id) {
        $terms = get_terms(array(
            'taxonomy' => 'pa_'.$attribute,
            'hide_empty' => false,
        ));

        $product_attributes[$i] = new WC_Product_Attribute();
        $product_attributes[$i]->set_id($att_id);
        $product_attributes[$i]->set_name('pa_'.$attribute);
        $product_attributes[$i]->set_options(wp_list_pluck($terms,'name'));
        $product_attributes[$i]->set_visible(true);
        $product_attributes[$i]->set_variation(true);

        $i++;
    }
    return $product_attributes;
}
