<?php
class tweetFactory{

  /**
   * $search_terms takes and array of strings
  **/
  public function __construct(){
    $this->tw = curl_init();    
    $this->db();
  }

  /**
   * Provide a database conenction with PDO
   */
  private function db(){
    $db_name = "??????????";
    $user = "???????????"
    $password = "???????????";
    
    try {
      $db = new PDO("mysql:host=localhost;dbname=$db_name", $user, $password);
      /*** set the error reporting attribute ***/
      $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);           
    }
    catch(PDOException $e)
    {
      echo $e->getMessage();
    }

    $this->db = $db;
  }


  /**
   * The main loop.
  **/
  public function store_tweets($search_terms){

    if(!is_array($search_terms)) $search_terms = array($search_terms);
    $this->search_terms = $search_terms;
    $this->remaining_tweets = TRUE;
    $this->pass = 1;
    
    while($this->remaining_tweets){     

      $results = $this->search();

      if( sizeOf($results) == 0) $this->next_pass();

      $tweet_count_before = $this->get_tweet_count();

      foreach ($results->entry as $tweet) {
        $this->record($tweet);
      }

      $tweet_count_after = $this->get_tweet_count();      

      //We use a crude but effective method to see if we have all the results that Twitter has to offer
      //Basically, if the database isn't getting any bigger, then we're done :)
      if($tweet_count_after == $tweet_count_before) $this->next_pass();

    }
  }

  /**
   * The clever thing about this whole class is that it can search over a large amount of search terms.
   * For large amounts of search terms (~100) the Twitter search API chokes with a "Query too complex" error.
   * Therefor we need to chunk up the queries into manageable byte sizes;
   */
  private function next_pass(){
    $this->pass++;    
  }

  /**
   * Make the actual API call via cURL
   */
  private function search(){
    $query = $this->generate_query();
    $search = "http://search.twitter.com/search.atom?$query&rpp=100&max_id={$this->max_id}";
    //echo $search."\n";die;

    curl_setopt($this->tw, CURLOPT_URL, $search);
    curl_setopt($this->tw, CURLOPT_RETURNTRANSFER, TRUE);
    $results = curl_exec($this->tw);
    //echo $results;die;
    //echo $pass;

    return new SimpleXMLElement($results);
  }

  /**
   * Form the query part of the API call
   */
  public function generate_query(){
    
    $chunk = 30; //how many search terms to include per API call
    $offset = ($this->pass * $chunk) - $chunk;
    $terms = array_slice($this->search_terms, $offset, $chunk);
    
    if(sizeOf($terms) == 0) $this->remaining_tweets = FALSE; //All done :D

    foreach($terms as $term){
      $query[] = $term;
    }
    
    return 'ors=' . urlencode(implode($query, '+'));
    
  }

  /**
   * Place the tweet in the DB.
   *
   * Uses INSERT INTO ... DUPLICATE KEY UPDATE so you don't have to worry about duplicates.
   *
   * Hopefully, using the mulitple pass method of this class (rather than say, using the max_id parameter offered
   * by the API) duplicates will only be upodated infrequently. However, this is also a desirable effect in that
   * we can ustilise the row count for the table in order to detect when we've started inserting exisitng tweets.
   * 
   */
  private function record($tweet){
    
    $id = explode(':', $tweet->id);
    $id = $id[2];
    
    //this number just gets bigger and bigger and bigger :)
    //It's used in the API query string to paginate
    $this->max_id = $id; 
    
    $content = mysql_escape_string($tweet->content);

    $author = explode(' ', $tweet->author->name);
    $handle = $author[0];
    $links = $tweet->link;
    $avatar = $links[1]['href'];

    $time = strtotime($tweet->published); //save tweets as UNIX timestamp
    
    if( $id > 0 ){
      $sql = "INSERT INTO tweets (id, content, published, handle, avatar)
              VALUES('$id', '{$content}', '{$time}', '{$handle}' , '{$avatar}')
              ON DUPLICATE KEY UPDATE id=id";
      try{
        $r = $this->db->exec($sql);
        return $id;
      }catch(Exception $e){
        echo "DB error: " . $e->getMessage();
      }
    }    
  }

  /**
   * Simply check to see how many tweets we have.
   *
   * Used in order to detect when we've started adding tweets that are already recorded
   */   
  private function get_tweet_count(){
    return $this->db->query("SELECT COUNT(*) as count FROM tweets")->fetch(PDO::FETCH_OBJ)->count;
  }

  /**
   *  Get tweets from the DB and return them as JSON
   *
   * @param $filter string Allows you to filter your query for a specific term
   * @param $from int Lower limit for pagination
   * @param $to int Upper limit for pagination
   *
   * @return JSON (writes to file tweets.json, if called from the CLi)
   */
  public function return_tweets($filter = FALSE, $from = 0, $to = -1){

    $filter = mysql_escape_string($filter);
    $from = mysql_escape_string($from);
    $to = mysql_escape_string($to);
    
    //Output as JSON if not called from CLi
    //Write a file of JSON if not
    $write_file = (php_sapi_name() == 'cli' && empty($_SERVER['REMOTE_ADDR'])) ? TRUE : FALSE;       

    if( $filter !== FALSE ){      
      $WHERE = "WHERE handle = '$filter'";
    }    

    $from = ($from > 0) ? "LIMIT $from" : "";
    $to = ($to > 0) ? ", $to" : "";
    $LIMIT = "$from $to";
    $q = "SELECT * FROM tweets $WHERE ORDER BY published DESC $LIMIT";
    
    $tweets = $this->db->query($q)->fetchAll(PDO::FETCH_OBJ);    
    
    foreach( $tweets as $tweet ){
      $replace = array("\r", "\n");
      $tweet->content = str_replace($replace, '<br />', addslashes($tweet->content));
      $tweet->published = date('c', $tweet->published);
      $tweet->published = $tweet->published;
      $o[] = $tweet;      
    }

    $o = json_encode($o);

    if($write_file){
      $myFile = dirname(__FILE__) . '/tweets.json';
      $fh = fopen($myFile, 'w') or die("Can't open file to output JSON into\n");
      fwrite($fh, $o);
      fclose($fh);
    }else{
      header('Content-Type: text/javascript');
      echo $o;
    }

    
  }

  public function __destruct(){
    curl_close($this->tw);
  }
}
?>
