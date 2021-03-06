<?php
/*
      LICENSE: GPL v2


      Copyright 2017 MagCho, uria

      This program is free software; you can redistribute it and/or
      modify it under the terms of the GNU General Public License
      as published by the Free Software Foundation; either version 2
      of the License, or (at your option) any later version.

      This program is distributed in the hope that it will be useful,
      but WITHOUT ANY WARRANTY; without even the implied warranty of
      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
      GNU General Public License for more details.

      You should have received a copy of the GNU General Public License
      along with this program; if not, write to the Free Software
      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

 */
/**
 * Name   :  Tsukushi
 * Github :  https://github.com/magcho/Tsukushi
 * Author :  MagCho, https://github.com/magcho
 *        :  uria, https://github.com/uriahome
 *
 * LICENCE: GPL v2
 */


/**
 * つくし
 * public @var $DB_host {string} SQLのホスト名
 * public @var $error_info {array} エラー時はfalseが返却され、エラーコードがこの変数に格納される
*/
class Tsukushi{
//default seting
 // DB connection setting default
  public $DB_host;
  public $DB_user;
  public $DB_pass;
  public $DB_name;
  public $DB_charset;

 //DB dic setting default
  public $DB_word_convert;
  public $DB_word_score;
  public $DB_word_AA_score;
  public $DB_word_emoji_score;
 // yahoo apis appid setting
  public $APPID;

//error message
  public $error_info = [];

// sentence included emoji or ASCII art
  private $emoji_list = [];
  private $aa_list = [];


  /**
   * 初期設定をするコンストラクタ
   * @param $array コンストラクタへ渡すプロパティー
   */
  public function __construct($array){
    $this->DB_host = isset($array['DB_host']) ? $array['DB_host'] : 'localhost';
    $this->DB_user = isset($array['DB_user']) ? $array['DB_user'] : 'root';
    $this->DB_pass = isset($array['DB_pass']) ? $array['DB_pass'] : 'password';
    $this->DB_name = isset($array['DB_name']) ? $array['DB_name'] : 'kaken';
    $this->DB_charset = isset($array['DB_charset']) ? $array['DB_charset'] : 'utf8mb4';


    $this->DB_word_convert = isset($array['DB_word_convert']) ? $array['DB_word_convert'] : 'word_convert_dic_table';
    $this->DB_word_score = isset($array['DB_word_score']) ? $array['DB_word_score'] : 'word_score_tweet_dic_table';
    $this->DB_word_AA_score = isset($array['DB_word_AA_score']) ? $array['DB_word_AA_score'] : 'word_score_AA_table';
    $this->DB_word_emoji_score = isset($array['DB_word_emoji_score']) ? $array['DB_word_emoji_score'] : 'word_score_emoji_table';

    $this->APPID = isset($array['APPID']) ? $array['APPID'] : '';
  }


  /**
   * mysqlのクエリーの特殊文字をエスケープする(mysqlとlike用のエスケープ)
   * @param  {string} $query クエリー
   * @return {string}          クエリー
   */
  public function escapeQuery($query){
    // $query = str_replace("\\\\","\\\\\\\\") // ?どうしたもんかねぇ
    $query = str_replace(["\\","_","'","%",","],["\\\\","\\\_","\\'","\\\%","\,"],$query);
    return $query;
  }


 /**
     * 最深レイヤーとノードの情報を取得する
     * @param  [type] $toArray $sentence_flowを渡す引数
     * @param  [type] $option  1: 最深レイヤーの階層数、2: 一番下のノード番号
     * @return [type]          [description]
     */
    private function getMaxLayer($toArray, $option) {
        $MAX_Layer = 0;
        foreach ($toArray as $value) {
            if ($value['layer'] > $MAX_Layer) {
                $MAX_Layer = $value['layer'];
            }
        }
        switch ($option) {
            case 1:
                return (int)$MAX_Layer;
            break;
            case 2:
                foreach ($toArray as $key => $value) {
                    if ($value['layer'] == $MAX_Layer) {
                        return (int)$key;
                    }
                }
            break;
            default:
              $this->error_info[] = [
                'messsge' =>  'getMaxLayer関数の第２引数が不正です',
                'code' => 'getMaxLayer'
              ];
              return false;
            break;
        }
    }
    /**
     *
     * @param  {array} $toArray    文節の区切り情報を格納した変数($sentence_flow)
     * @param  {int} $currentNode 検索の基準となるノード番号
     * @param  {int} $option      1: $currentNodeの親のノード番号を取得
     *                              @return {int} 親のノード番号
     *                            2: $currentNodeの兄弟ノード番号を配列で取得
     *                              @return {array} 兄弟ノード番号を配列返す
     */
    private function getNodeFamily($toArray, $currentNode, $option) {
        switch ($option) {
            case 1:
             /**
              * $currentNodeの親のノード番号を取得
              */
                return $toArray[$currentNode]['to'];
            break;
            case 2:
            /**
             * $currentNodeの兄弟ノード番号を配列で取得
             */
                $motherNode = $toArray[$currentNode]['to'];
                $i = 0;
                foreach ($toArray as $key => $value) {
                    if ($value['to'] == $motherNode) {
                        $returnArray[$i] = $key;
                        ++$i;
                    }
                }
                return $returnArray;
            break;
            default:
            $this->error_info[] = [
              'messsge' =>  'getNodeFamily関数の第３引数が不正です',
              'code' => 'getNodeFamily'
            ];
            return false;
            break;
        }
    }

/**
 * 文章を解析して点数をつけるメソッド
 * @param $sentence {stirng} 解析したい文章
 * @return          {float}  文章の点数を返却する
 */
  public function getscore($sentence){

    if(gettype($sentence) != 'string'){
      $this->error_info[] = [
        'message' => '第一引数がstring型ではありません',
        'code' => 'getscore'
      ];
      return false;
    }
    if(!isset($this->APPID)){
      $this->error_info[] = [
        'message' => 'APPIDが指定されていません',
        'code' => 'getscore'
      ];
      return false;
    }


  /**
   * sentenceから絵文字を抽出して削除
   * sentenceからAAを抽出して削除
   */
    require_once(__DIR__."/emoji.php");
    require_once(__DIR__."/aa.php");
    $this->emoji_list = extract_emoji($sentence);
    $this->aa_list = extract_aa($sentence);
    $sentence = remove_emoji($sentence);
    $sentence = remove_aa($sentence);


  /**
   * yahoo apis で文章から分節間の係り受け関係を取得
   * @param @var $APPID    {stirng}      yahoo appid
   * @param @var $sentence {string}  解析したい文章
   * @return @var $sentence_flow {array} 解析結果
   **/

    $text = urlencode($sentence);
    $text = str_replace(["\r","\n"],"", $text);
    $url = "https://jlp.yahooapis.jp/DAService/V1/parse?appid={$this->APPID}&sentence={$text}";
    // $xml = simplexml_load_file($url);
    $ch = curl_init(); // 初期化
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); // 出力内容を受け取る設定
    $result = curl_exec( $ch ); // データの取得


    if($result === false) { // エラーをチェックし、エラーメッセージを表示します
      $this->error_info[] = "curl error:".curl_errno($ch).": ".curl_error($ch);
      return false;
    }

    $xml = simplexml_load_string($result);
    if(isset($xml->Error->Message)){
      // apiエラー時の処理
      switch (curl_errno($ch)) {
        case '400':
          $this->error_info[] = [
            'message' => '400 係り受けapiへのパラメータが不正です。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '401':
          $this->error_info[] = [
            'message' => '401 係り受けapiへの許可されていないアクセスです。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '403':
          $this->error_info[] = [
            'message' => '403 係り受けapiの利用可能回数を超えたか、APPIDが無効です。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '404':
          $this->error_info[] = [
            'message' => '404 係り受けapiのURLが変更されています。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '500':
          $error_info[] = [
            'message' => '500 係り受けapiのInternal Server Error 時間を空けて再実行してください。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '503':
          $this->error_info[] = [
            'message' => '503 係り受けapiのService unavailable 時間を空けて再実行してください。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        default:
          $this->error_info[] = [
            'message' => '不明なステータスコードです。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;
      }
      return false;
    }
    curl_close($ch);
    $seg = null;
    $chunks = $xml->Result->ChunkList->Chunk;
    foreach ($chunks as $chunk) {
      $id = (int) $chunk->Id;
      $dependency = (int) $chunk->Dependency;
      $morphems = $chunk->MorphemList->Morphem;
      foreach ($morphems as $morphem) {
          $seg = $seg.$morphem->Surface;
      }
      $sentence_flow[$id]['to'] = $dependency;
      $str[$id][$dependency] = $seg;
      $seg = '';
    }
    for ($i = 0;$i < count($str);++$i) {
      $key = array_keys($str[$i]);
      $sentence_flow[$i]['value'] = $str[$i][$key[0]];

      // 初期化処理
      $sentence_flow[$i]['score'] = 0.0;
      $sentence_flow[$i]['layer'] = 0;
    }



   /**
    * 文章から単語に形態素解析
    * @param @var {sring} $sencente 解析したい文章
    * @return @var {array} $sentence_word
    */
    $sentence_word = array();
    $i = 0;
    $url = "https://jlp.yahooapis.jp/MAService/V1/parse?appid={$this->APPID}&results=ma";
    $url .= '&sentence='.urlencode($sentence);
    // $xml = simplexml_load_file($url);
    $ch = curl_init(); // 初期化
    curl_setopt( $ch, CURLOPT_URL, $url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); // 出力内容を受け取る設定
    $result = curl_exec( $ch ); // データの取得
    if($result === false) { // エラーをチェックし、エラーメッセージを表示します
      $this->error_info[] = "curl error:".curl_errno($ch).": ".curl_error($ch);
      return false;
    }
    $xml = simplexml_load_string($result);
    if(isset($xml->Error->Message)){
      // apiエラー時の処理
      switch (curl_errno($ch)) {
        case '400':
          $this->error_info[] = [
            'message' => '400 形態素apiへのパラメータが不正です。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '401':
          $this->error_info[] = [
            'message' => '401 形態素apiへの許可されていないアクセスです。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '403':
          $this->error_info[] = [
            'message' => '403 形態素apiの利用可能回数を超えたか、APPIDが無効です。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '404':
          $this->error_info[] = [
            'message' => '404 形態素apiのURLが変更されています。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '500':
          $this->error_info[] = [
            'message' => '500 形態素apiのInternal Server Error 時間を空けて再実行してください。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        case '503':
          $this->error_info[] = [
            'message' => '503 形態素apiのService unavailable 時間を空けて再実行してください。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;

        default:
          $this->error_info[] = [
            'message' => '不明なステータスコードです。'.$xml->Error->Message,
            'code' => 'getscore'
          ];
          break;
      }
      return false;
    }
    curl_close($ch);
    foreach ($xml->ma_result->word_list->word as $value) {
      $sentence_word[$i]['word'] = (string) $value->surface;
      $sentence_word[$i]['score'] = 0.0;
      ++$i;
    }

    /**
     * 文節に対応する単語に[flow]属性をつける
     * @var $sentence_flow {array}
     * @var $sentence_word {array}
     */
    $i = 0;
    foreach ($sentence_flow as $key => $value) {
      for ($M = count($sentence_word);$i < $M; ++$i) {
        if (strpos($value['value'], $sentence_word[$i]['word']) !== false) {
          $sentence_word[$i]['flow'] = $key;
        } else {
          break;
        }
      }
    }

    /**
     * 単語を辞書形に正規化
     */
    // DBコネクション
    $dsn = "mysql:dbname={$this->DB_name};host={$this->DB_host};charset={$this->DB_charset}";
    try{
      $dbh = new PDO($dsn, $this->DB_user, $this->DB_pass);
    }catch (PDOException $e){
      // print('Connection failed:'.$e->getMessage());
      $this->error_info[] = [
        'message' => '単語を辞書形に正規化 Connection failed:'.$e->getMessage(),
        'code' => 'getscore'
      ];
      return false;
    }

    // クエリ生成
    $queryword = "'";// シングルクォーテーション入れる
    foreach($sentence_word as $k){
      $k['word'] = $this->escapeQuery($k['word']);
      $queryword .= $k['word']."','";
    }
    $queryword = substr($queryword,0,-2);//後ろから3文字つまり’,’を消す
    $queryword = preg_replace("/\,'\s'\,/",",",$queryword); // クエリの空白を削除
    $query = "SELECT * FROM `{$this->DB_word_convert}` WHERE `changed` IN({$queryword})";
    // クエリ発行
    $stmt = $dbh->query($query);

    // DBのレスポンスから$sentence_wordを更新
    while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $responce[] = $data;
    }
    for ($i = 0, $SENTENCE_WORD_LEN = count($responce);$SENTENCE_WORD_LEN > $i;++$i) {
      foreach ($responce as $DBrow) {
        if ($sentence_word[$i]['word'] == $DBrow['changed']) {
          $sentence_word[$i]['word'] = $DBrow['origin'];
        }
      }
    }


   // 単語から点数を算出
    // クエリ生成
    $queryword = "'";// シングルクォーテーション入れる
    foreach($sentence_word as $k){
      $k['word'] = $this->escapeQuery($k['word']);
       $queryword .= $k['word']."','";
    }
    $queryword = substr($queryword,0,-2);//後ろから3文字つまり’,’を消す
    $query = "SELECT * FROM `{$this->DB_word_score}` WHERE `word` IN({$queryword})";
    // クエリ発行
    $stmt = $dbh->query($query);
    // echo $query;

    // DBのレスポンスから$sentence_wordを更新
    while($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
     $responce[] = $data;
    }
    for ($i = 0, $SENTENCE_WORD_LEN = count($sentence_word) - 1;$i <= $SENTENCE_WORD_LEN;++$i) {
      foreach ($responce as $value) {
        if ($sentence_word[$i]['word'] == $value['word']) {
          $sentence_word[$i]['score'] = (float)$value['score'] + 0.0;
        }
      }
    }

   // 単語の点数を文節内で平均化
    $sum = [];
    $count = [];
    foreach ($sentence_flow as $key1 => $value1) {
      foreach ($sentence_word as $value2) {
        if ($value2['flow'] == $key1) {
          $sum[$key1] += $value2['score'];
          ++$count[$key1];
        }
      }
      foreach ($count as $key3 => $value3) {
        $sentence_flow[$key1]['score'] = $sum[$key3] / $value3;
      }
    }




   // 点数を算出
    // rootノードを検索
    $currentNode = 0;

    foreach ($sentence_flow as $key1 => $value1) {
      if ($value1['to'] == -1) {
        $rootNodeNum = $key1;
        $currentNode = $rootNodeNum;
      }
    }




    /**
     * 幅優先探索でレイヤー階層を取得
     * @var {int} $rootNodeNum ルートノードの$sentence_flow上の引数
     * @var {array} $stack $カレントレイヤーに属するノードの子ノード(次に走査するノードを予約する)
     */
    $currentLayer = 1;
    $cue[] = $rootNodeNum;

    while (count($cue) !== 0) {
      $do = array_shift($cue); // キューを取り出して消す
      // $this->v($do);
      $sentence_flow[$do]['layer'] = $currentLayer;
      foreach ($sentence_flow as $key => $value) { // カレントレイヤーの子ノードを探す
        if($value['to'] == $do){
          if($key !== 0){
            $nextCue[] = $key;
          }
        }
      }
      if(count($cue) == 0){
        // カレントレイヤーのキューが全て走査し終わったら、次のレイヤーのキューを代入
        $cue = $nextCue;
        $nextCue = null;
        $currentLayer++;
      }
    }
    // 末端ノードにはレイヤー情報がつかないので、別途付加
    foreach ($sentence_flow as $key => $value) {
      if($value['layer'] == 0){
        $sentence_flow[$key]['layer'] = $sentence_flow[$value['to']]['layer']+1;
      }
    }





    $currentNodeNum = $this->getMaxLayer($sentence_flow, 2);
    $flag = false;
    while (!$flag) {
      $sibling = $this->getNodeFamily($sentence_flow, $currentNodeNum, 2);
      $result = 0;
      for ($node_size = count($sibling), $i = 0;$i < $node_size;++$i) {
        $j = $sibling[$i];
        $result+= $sentence_flow[$j]['score'];
      }
      // echo '>> sentence_flow[' . $currentNodeNum . ']の兄弟は' . $node_size . '個 * * 合計=' . $result . '点 * ';
      $result = $result / $node_size;
      // echo '平均=' . $result . '点<br />';
      $motherNodeNum = $this->getNodeFamily($sentence_flow, $currentNodeNum, 1);
      $sentence_flow[$motherNodeNum]['score'] = ($sentence_flow[$motherNodeNum]['score'] + $result) / 2;
      for ($node_size = count($sibling), $i = 0;$i < $node_size;++$i) {
        $j = $sibling[$i];
        $sentence_flow[$j]['layer'] = 0;
      }
      $currentNodeNum = $this->getMaxLayer($sentence_flow, 2);
      if ($currentNodeNum == $rootNodeNum) {
        $flag = true;
        $sentence_result = $sentence_flow[$rootNodeNum]['score'];
      }
    }


  /**
   * AAの点数を加味する
   */
    // クエリ生成
    if(count($this->aa_list) != 0){
      $queryword = "'";// シングルクォーテーション入れる
      foreach($this->aa_list as $k){
        $queryword .= $k."','";
      }
      $queryword = substr($queryword,0,-2);//後ろから3文字つまり’,’を消す
      print_r($this->aa_list);
      $query = "SELECT * FROM `{$this->DB_word_AA_score}` WHERE `aa` IN({$queryword})";
      echo $query;
      // クエリ発行
      $stmt = $dbh->query($query);
      $sum = $count = 0;
      while($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
       $sum += $data['score'];
       $count++;
      }
      $sentence_result = (($sum/$count)+$sentence_result)/2;
    }

  /**
   * emojiの点数を加味する
   */
    // クエリ生成
    if(count($this->emoji_list) != 0){
      $queryword = "'";// シングルクォーテーション入れる
      foreach($this->emoji_list as $k){
        $queryword .= $k."','";
      }
      $queryword = substr($queryword,0,-2);//後ろから3文字つまり’,’を消す
      $query = "SELECT * FROM `{$this->DB_word_emoji_score}` WHERE `emoji` IN({$queryword})";
      // クエリ発行
      $stmt = $dbh->query($query);
      $sum = $count = 0;
      while($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sum += $data['score'];
        $count++;
      }
      $sentence_result = (($sum/$count)+$sentence_result)/2;
    }




    return $sentence_result;
  }

/**
 * 整形されたログを吐くメソッド
 * @param  {なんでもいい} $var 中身を見たい変数
 */
  private function v($var){
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
  }
}
