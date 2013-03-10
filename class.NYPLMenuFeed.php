<?php
/**
 * A class for producing an Atom feed of menu records from the NYPL archive DB
 *
 * <code>
 * $pg = 1;
 * $feed = new NYPLMenuFeed($pg);
 * $feed->setLimit(25);
 * $feed->getFeed();
 * echo $feed->output();
 * </code>
 *
 * @author robyn overstreet <robynover@gmail.com>
 */
class NYPLMenuFeed {
    public $limit = 100; //number of results per page
    public $current_page;
    private $total_pages;
    private $_db;
    private $_dsn;
    private $_user;
    private $_pw;
    private $domdoc;
    private $menu_currency_symbol;
    private $currency_placed_before;
    private $base_url = 'http://localhost:8888/nypl_labs/nypl_menu_feed.php';

    /**
     * constructor sets current page number and gets total number of pages
     * @param int $pg
     */
    public function __construct($pg = 1){
        if (!is_numeric($pg)){ //sanity check
            $pg = 1;
        }
        $this->total_pages = $this->getTotalPages();
        if ($pg > $this->total_pages){ //don't allow a page number out of range
            $pg = $this->total_pages;
        }
        $this->current_page = $pg;
    }

    /**
     * Change the limit property, i.e., the number of records per page
     *
     * @param int $num
     */
    public function setLimit($num){
        if (is_numeric($num)){
           $this->limit = $num; 
        }
    }

    /**
     * Bring in database credentials from outside the web directory
     * @return bool
     */
    private function getDBCredentials(){
        require_once('inc.dbinfo.php');
        if ($dsn && $user && $pw){
            $this->_dsn = $dsn;
            $this->_user = $user;
            $this->_pw = $pw;
            return true;
        }
        return false;
    }

    /**
     * Produce the Atom feed from the DB query
     *
     * @return bool
     */
    public function getFeed(){
        $q = <<<EOT
SELECT locations.name AS venue,date_of_menu,menus.id AS menu_id,
menus.updated_at,symbol,price,is_placed_before,
GROUP_CONCAT(CONCAT(dishes.id,'###',dishes.name) SEPARATOR '@@') AS dishnames,
GROUP_CONCAT(CONCAT(dishes.id,'###',price) SEPARATOR '@@') AS dishprices
FROM menus
LEFT JOIN locations ON locations.id = menus.location_id
LEFT JOIN currencies ON currencies.id = menus.currency_id
LEFT JOIN menu_pages ON menu_pages.menu_id = menus.id
LEFT JOIN menu_items ON menu_items.menu_page_id = menu_pages.id
LEFT JOIN dishes ON menu_items.dish_id = dishes.id
GROUP BY menus.id
ORDER BY date_of_menu DESC
LIMIT %d,%d
EOT;
        $offset = $this->limit * ($this->current_page -1);
        $sql = sprintf($q,$offset,$this->limit);
        
        if (!$this->_db){
           try{
                $this->getDBCredentials();
                $this->_db = new PDO($this->_dsn,$this->_user,$this->_pw);
            } catch (PDOException $e) {
                //this error message for development purposes only
                echo "Database Error: ".$e->getMessage();
                return false;
            }
        }
        $feed = $this->startAtomFeed();
        $results = $this->_db->query($sql);
        if (!empty($results)){
            foreach ( $results as $row){
                //---get currency info
                $this->menu_currency_symbol = $row['symbol'];
                $this->currency_placed_before = $row['is_placed_before'];

                //---add entry node
                $entry = $this->appendNewElement('entry',NULL,$feed);

                //id node
                $this->appendNewElement('id','http://menus.nypl.org/menus/'.$row['menu_id'],$entry);
                //--updated node
                $updated_time = date(DATE_ATOM,strtotime($row['updated_at']));
                $this->appendNewElement('updated',$updated_time,$entry);
                 //link node
                $link = $this->domdoc->createElement('link');
                $link->setAttribute('href','http://menus.nypl.org/menus/'.$row['menu_id']);
                $entry->appendChild($link);

                //---create date object, for use in title and summary
                $date = new DateTime($row['date_of_menu']);

                //---add title node
                $venue = htmlspecialchars($row['venue']);
                $this->appendNewElement('title', $venue.', '.$date->format('Y'), $entry);

                //---add summary node, format the date
                $formatted_date = $date->format('F j, Y');
                $summary_text = "$venue, $formatted_date";
                $summary = $this->appendNewElement('summary', $summary_text, $entry);

                //---deal with dishes and prices
                if ($row['dishnames']){
                    //---add content node if there are dishes
                    $content = $this->domdoc->createElement('content');
                    //---add type attribute for custom XML (i.e., not HTML) in this section
                    $content->setAttribute('type','application/xml');
                    $entry->appendChild($content);

                    //---get the dish and price info as a node
                    $dishes_el = $this->parseDishText($row['dishnames'],$row['dishprices']);
                    $content->appendChild($dishes_el);
                }
            }
        }
        return true;
    }

    /**
     * A shortcut to create and append a new DOM element (node)
     *
     * @param string $elname the name of the DOM element to create
     * @param mixed $value the value of the element if there is one, NULL if not
     * @param DOMElement $node The DOMElement to which to attach the new node
     * @return DOMElement 
     */
    private function appendNewElement($elname,$value,$node){
        $element = $this->domdoc->createElement($elname,$value);
        return $node->appendChild($element);
    }

    /**
     * Create a new DOMDocument and the initial nodes at the top of the feed (before the entries begin)
     *
     * @return DOMElement
     */
    private function startAtomFeed(){
        $dom = new DOMDocument('1.0', 'utf-8');
        $this->domdoc = $dom;
        //<feed>
        $root = $this->domdoc->createElement('feed');
        $root->setAttribute('xmlns','http://www.w3.org/2005/Atom');
        $feed = $this->domdoc->appendChild($root);
        //<title>NYPL Labs Menu Archive</title>
        $this->appendNewElement('title','NYPL Labs Menu Archive',$feed); 
        $updated = $this->getLastUpdateDate();
        $this->appendNewElement('updated', $updated, $feed);
        //<author><name>NYPL</name></author>
        $au = $this->appendNewElement('author', NULL, $feed);
        $this->appendNewElement('name', 'NYPL', $au);
        //<id>
        $this->appendNewElement('id',"http://menus.nypl.org/",$feed);
        //create links with pagination
        $this->createPaginationLinks($feed);
        return $feed;
    }

    /**
     * Transform strings of dish and price data from the database query into arrays and then into DOMElements
     *
     * The database returns a GROUP_CONCAT of the dish names and prices, separated by special characters
     *
     * @param string $dish_str the concatenated string of names of dishes and ids from the DB
     * @param string $price_str the concatenated string of prices and dish ids from the DB
     * @return DOMElement returns NULL if $dish_str is empty
     */
    private function parseDishText($dish_str,$price_str){
        if ($price_str){
            $prices = explode('@@',$price_str);
            $price_array = array();
            foreach($prices as $p){
                list($id,$price) = explode('###',$p);
                $price_array[$id]= number_format($price,2);
            }
        }
        if ($dish_str){
            //make dishes node
            $dishes_el = $this->domdoc->createElement('dishes');
            $dishes = explode('@@',$dish_str);
            foreach($dishes as $d){
               list($id,$dish) = explode('###',$d);
               $dish = htmlspecialchars($dish);
               //make dish node
               $dish_el = $this->appendNewElement('dish', $NULL, $dishes_el);
               //make dish-name node
               $this->appendNewElement('dish-name', $dish, $dish_el);
               //does this dish have a price?
               if (!empty($price_array)){
                   if (array_key_exists($id,$price_array)){
                       $dish_price = $price_array[$id];
                       if ($this->currency_placed_before){
                           $dish_price = $this->menu_currency_symbol.$dish_price;
                       } else {
                           $dish_price = $dish_price.$this->menu_currency_symbol;
                       }
                       //make dish-price node if it exists
                       $this->appendNewElement('dish-price', $dish_price, $dish_el);
                   }
               }
            }
            return $dishes_el;
        }
        return NULL;
    }

    /**
     * Return the total number of pages, based on number of records per page
     * and total number of menu records
     *
     * @return int
     */
    public function getTotalPages(){
       //get num rows in menus table
       if (!$this->_db){
           try{
                $this->getDBCredentials();
                $this->_db = new PDO($this->_dsn,$this->_user,$this->_pw);
            } catch (PDOException $e) {
                //this error message for development purposes only
                //echo "Database Error: ".$e->getMessage();
                exit;
            }
       }
        $sql = "SELECT COUNT(*) FROM menus";
        $row = $this->_db->query($sql)->fetch();
        $total_menu_rows = $row[0];
        //divide by num records per page (limit)
        return ceil($total_menu_rows/$this->limit);

    }

    /**
     * A loop to produce the several link nodes required at the top of the feed.
     * Includes links indicating pagination of the feed.
     *
     * @param DOMElement $node the node to which to attach the new link nodes
     * @return bool
     */

    private function createPaginationLinks($node){
        $linknames = array('self','first');
        $next_pg = $this->current_page + 1;
        $last_pg = $this->total_pages;
        if ($this->current_page > 1){
            $prev_pg = $this->current_page - 1;
            $linknames[] = 'previous';
        }
        if (is_numeric($next_pg) || is_numeric($last_pg)){
            if ($next_pg < $last_pg){
                $linknames[] ='next';
            }
            $linknames[] ='last';
        }
        
        foreach ($linknames as $ln){
            if (!$link = $this->domdoc->createElement('link')){
                return false;
            }
            switch($ln){
                case 'next':
                    $url = $this->base_url.'?pg='.$next_pg;
                    break;
                case 'last':
                     $url = $this->base_url.'?pg='.$last_pg;
                    break;
                case 'previous':
                    $url = $this->base_url.'?pg='.$prev_pg;
                    break;
                default:
                    $url = $this->base_url;
            }
            $link->setAttribute('rel',$ln);
            if ($ln == 'self'){
                $link->setAttribute('type','application/atom+xml');
            }
            $link->setAttribute('href',$url);
            $node->appendChild($link);
        }
        return true;
    }
    
    /**
     * Get the date of the last updated menu record
     * 
     * @return string
     */
    public function getLastUpdateDate(){
        if (!$this->_db){
            try{
                $this->getDBCredentials();
                $this->_db = new PDO($this->_dsn,$this->_user,$this->_pw);
            } catch (PDOException $e) {
                exit;
            }
        }
        $sql = "SELECT updated_at FROM menus ORDER BY updated_at DESC LIMIT 1";
        $row = $this->_db->query($sql)->fetch();
        return date(DATE_ATOM,strtotime($row['updated_at'])); 
    }

    /**
     * Produce XML string from the DOMDocument
     * @return string
     */
    public function output(){
        $this->domdoc->formatOutput = true;
        return $this->domdoc->saveXML();    
    }
}
?>
