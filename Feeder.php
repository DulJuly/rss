<?php

class Feeder {

    private $config= array(
        'cache_dir' => "./cache",
        'jsdati_user' => array(
            'user_name' => '',
            'user_pw' => '',
            'user_token' => ''
        )
    );

    function __construct() {
        $this->config['cache_dir'] = $this->path_handler($this->config['cache_dir']);
    }

    function path_handler($path) {
        if(end(explode('/', $path)) !== "") {
            $path .= "/";
        }
        return $path;
    }

    /**
     * 抓取页面
     * @param $url  页面地址
     * @param array $header 请求头
     * @param null $post_data  提交数据
     * @return mixed    服务器返回数据
     */
    function curl_get($url, $header = array(), $post_data = null) {
        $ch = curl_init();
        if(strpos($url, "https") !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_PROXY, "http://127.0.0.1:8888");
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.96 Safari/537.36");
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        if($post_data) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        }
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $res = curl_exec($ch);
        if($error = curl_error($ch)) {
            die($error);
        }
        curl_close($ch);
        return $res;
    }

    /**
     * 基于搜狗微信搜索，输入关键词，返回公众号搜索结果
     * @param $keywords
     * @return array|void
     */
    function get_wechat_number_results($keywords) {
        if(!$keywords) return;
        $url = "http://weixin.sogou.com/weixin?type=1&s_from=input&query=".urlencode(trim($keywords));
        $data = $this->curl_get($url);
        $data = preg_replace("/<!--[\s\S]*?-->/", "", $data); #去除注释
        preg_match_all("/<li id=\"sogou_vr_[\s\S]*?<\/li>/", $data, $pre_result);
        $link_avactor_pattern = "/.*?img-box\"[\s\S]*?<a.*?href=['\"](.*?)['\"][\s\S]*?<img.*?src=['\"](.*?)['\"].*?>/";
        $img_pattern = "/img-box\"[\s\S]*<img.*?src=['\"](.*?)['\"]/";
        $weid_pattern = "/em_weixinhao\">(.*?)<\/label>/";
        $title_pattern = "/.*?tit\"[\s\S]*?>[\s\S]*?<a.*?>([\s\S]*?)<\/a>/";
        $desc_pattern = "/<dt>功能介绍：<\/dt>[\s\S]*?<dd>(.*?)<\/dd>/";
        $auth_pattern = "/认证：<\/dt>[\s\S]*?<dd>(.*?)<\/dd>/";
        $recent_pattern = "/最近文章：[\s\S]*?(<a.*?<\/a>)/";
        $results = array();
        foreach($pre_result[0] as $key => $result_item) {
            preg_match($link_avactor_pattern, $result_item, $userinfo);
            preg_match($img_pattern, $result_item, $coverinfo);
            preg_match($title_pattern, $result_item, $titleinfo);
            preg_match($desc_pattern, $result_item, $descinfo);
            preg_match($auth_pattern, $result_item, $authinfo);
            preg_match($recent_pattern, $result_item, $recentinfo);
            preg_match($weid_pattern, $result_item, $weidinfo);
            $results[$key]['link'] = str_replace("&amp;", "&", $userinfo[1]);
            $results[$key]['avactor'] = @$userinfo[2];
            $results[$key]['weid'] = @$weidinfo[1];
            $results[$key]['cover'] = @$coverinfo[1];
            $results[$key]['title'] = @trim($titleinfo[1]);
            $results[$key]['desc'] = @trim($descinfo[1]);
            $results[$key]['auth'] = @trim($authinfo[1]);
            $results[$key]['recent'] = @trim($recentinfo[1]);
        }
        return $results;
    }

    //通过微信号搜索
    function get_by_wechat_id($wechat_id) {
        $search_results = $this->get_wechat_number_results(trim($wechat_id));
        foreach($search_results as $search_result) {
            if(trim($search_result['weid']) === $wechat_id) {
                return $search_result;
            }
        }
        return false;
    }

    //获取微信文章列表
    function get_article_list($url) {
        $page_result = $this->curl_get($url);
        $js_pattern = "/msgList =([\s\S]*?);/";
        preg_match($js_pattern, str_replace("&amp;", "&", $page_result), $js_str);
        $msgobj = json_decode($js_str[1]);
        $msglist = $msgobj->list;
        $count = 0;
        if(!$msglist && strpos($page_result, "id=\"verify_img\"") !== false) {
            if($this->verify_code_image() && $count <= 1) {
                $this->get_article_list($url);
                $count ++;
            }else {
                die("验证码暂时无法识别，请稍后再试...");
            }
        }
        $articles = [];
        if($msglist) {
            foreach($msglist as $single_msg) {
                $app_msg_ext_info = $single_msg -> app_msg_ext_info;
                $comm_msg_info = $single_msg -> comm_msg_info;
                $articles[md5($app_msg_ext_info -> title)]['author'] = $app_msg_ext_info -> author;
                $articles[md5($app_msg_ext_info -> title)]['url'] = 'https://mp.weixin.qq.com'.$app_msg_ext_info -> content_url;
                $articles[md5($app_msg_ext_info -> title)]['desc'] = $app_msg_ext_info -> digest;
                $articles[md5($app_msg_ext_info -> title)]['cover'] = $app_msg_ext_info -> cover;
                $articles[md5($app_msg_ext_info -> title)]['title'] = $app_msg_ext_info -> title;
                $articles[md5($app_msg_ext_info -> title)]['time'] = $comm_msg_info -> datetime;
                if(!empty($app_msg_ext_info->multi_app_msg_item_list)) {
                    $multi_app_msg_item_list = $app_msg_ext_info->multi_app_msg_item_list;
                    foreach($multi_app_msg_item_list as $multi_app_msg_item) {
                        $articles[md5($multi_app_msg_item -> title)]['author'] = $multi_app_msg_item -> author;
                        $articles[md5($multi_app_msg_item -> title)]['url'] = 'https://mp.weixin.qq.com'.$multi_app_msg_item -> content_url;
                        $articles[md5($multi_app_msg_item -> title)]['desc'] = $multi_app_msg_item -> digest;
                        $articles[md5($multi_app_msg_item -> title)]['cover'] = $multi_app_msg_item -> cover;
                        $articles[md5($multi_app_msg_item -> title)]['title'] = $multi_app_msg_item -> title;
                        $articles[md5($multi_app_msg_item -> title)]['time'] = $comm_msg_info -> datetime;
                    }
                }
            }
        }
        return $articles;
    }

    //抓取微信文章
    function get_article($article_data, $output = false) {
        if(!is_array($article_data)) return;
        $url = $article_data['url'];
        $title = $article_data['title'];
        $cache_dir = $this->config['cache_dir'];
        #检测缓存中是否存在
        if(!file_exists($cache_dir.md5($title))) {
            $page_result = $this->curl_get($url);
        }else {
            $page_result = file_get_contents($cache_dir.md5($title));
        }
        $article_data['content'] = $page_result;
        $this->add_cache($article_data);
        if($output) {
            return $article_data['content'];
        }
    }

    function dir_check($path,$flag=true) {
        if($path && !file_exists($path) && !is_dir($path)) {
            if($flag) {
                mkdir($path, 0777, true);
                return true;
            }else {
                return false;
            }
        }else {
            return true;
        }
    }

    //添加缓存
    function add_cache($item) {
        $cache_dir = $this->config['cache_dir'];
        $this->dir_check($cache_dir);
        #防止重复加入缓存
        $filename = $cache_dir.md5($item['title']);
        if(!file_exists($filename)) {
            file_put_contents($filename, $item['content']);
        }
    }

    function feeder($wechat_id) {
        $wechat_result = $this -> get_by_wechat_id($wechat_id);
        $wechat_desc = $wechat_result['desc'];
        $wechat_article_index_link = $wechat_result['link'];
        $articles = $this->get_article_list($wechat_article_index_link);
        foreach($articles as $key => $article) {
            $articles[$key]['content'] = $this->get_article($article, true);
        }
        $articles['title'] = $wechat_result['title'];
        $articles['link'] = $wechat_result['link'];
        $articles['desc'] = $wechat_desc;
        return $articles;
    }

    /**
     * @param $wechat_id 唯一标识符,微信号
     * @param $timeout 缓存过期时间 单位：分钟
     * @return boolean 为true时，需要发生更新
     */
    function rss_timer($wechat_id, $timeout) {
        $rss_cache_dir = $this->config['cache_dir']."rss/";
        $this->dir_check($rss_cache_dir);
        $rss_filename = $rss_cache_dir.md5($wechat_id);
        if(!file_exists($rss_filename)) {
            return true;
        }else {
            $mtime = filemtime($rss_filename);
            if((time() - $mtime)/60 > $timeout) {
                return true;
            }
        }
        return false;
    }

    /**
     * 生成rss文件
     * @param $wechat_id    微信号
     * @param int $timeout 缓存过期时间，单位：分钟
     * @return string   rss字符串
     */
    function generate_rss($wechat_id, $timeout=10) {
        $rss_cache_dir = $this->config['cache_dir']."rss/";
        $rss_file_str = @trim(file_get_contents($rss_cache_dir.md5($wechat_id)));
        if($rss_file_str && !empty($rss_file_str) && !$this->rss_timer($wechat_id, $timeout)) {
            $rss_str = $rss_file_str;
        }else {
            $articles = $this->feeder($wechat_id);
            $rss_str = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<rss version=\"2.0\">\r\n\t<channel>
\t\t<title>{$articles['title']}</title>
\t\t<link>".str_replace("&", "&amp;", $articles['link'])."</link>
\t\t<description>{$articles['desc']}</description>\r\n";
            foreach($articles as $article) {
                if(is_array($article)) {
                    $rss_str .= "\t\t<item>\r\n";
                    $rss_str .= "\t\t\t<title>".$article["title"]."</title>\r\n";
                    $rss_str .= "\t\t\t<link>".str_replace("&", "&amp;", $article["url"])."</link>\r\n";
                    $rss_str .= "\t\t\t<description>".$article["desc"]."</description>\r\n";
                    $rss_str .= "\t\t\t<language>zh-cn</language>\r\n";
                    $rss_str .= "\t\t\t<author>".$article["author"]."</author>\r\n";
                    $rss_str .= "\t\t\t<pubDate>".date(DATE_RSS, $article["time"])."</pubDate>\r\n";
                    $rss_str .= "\t\t</item>\r\n";
                }
            }
            $rss_str .= "\t</channel>\r\n</rss>";
            $this->dir_check($rss_cache_dir);
            file_put_contents($rss_cache_dir.md5($wechat_id), $rss_str);
        }

        return $rss_str;
    }

    function generate_rss_address($wechat_id) {
        return 'http://'.$_SERVER['HTTP_HOST'].'/spider/rss/cache/rss/'.md5($wechat_id);
    }

    //打码
    function jsdati_upload($image, $type_mark, $minlen, $maxlen) {
        set_time_limit(0);
        if(class_exists('CURLFile')) {
            $data_arr['upload'] = new CURLFile(realpath($image));
        }else {
            $data_arr['upload'] = '@'.realpath($image);
        }
        $data_arr['yzm_minlen'] = $minlen;
        $data_arr['yzm_maxlen'] = $maxlen;
        $data_arr['yzmtype_mark'] = $type_mark;
        return $this->jsdati_post('upload', $data_arr);
    }

    /*
     * 验证码报错函数
     *
     * $yzm_id:[必填]验证码上传成功后返回的id
     */
    function jsdati_error($yzm_id) {
        return $this->jsdati_post('error', array('yzm_id'=>$yzm_id));
    }

    /*
     * 查询账户点数函数
     */
    function jsdati_point() {
        return $this->jsdati_post('point');
    }

    function jsdati_post($type, $val=null) {
        $user = $this->config['jsdati_user'];
        $data['user_name'] = $user['user_name'];
        $data['user_pw'] = $user['user_pw'];
        $data['zztool_token'] = $user['user_token'];
        if (is_array($val)) {
            $data = $data + $val;
        }
        $http = curl_init("http://v1-http-api.jsdama.com/api.php?mod=php&act={$type}");
        curl_setopt($http, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($http, CURLOPT_POST, 1);
        curl_setopt($http, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($http);
        curl_close($http);
        return $result;
    }

    function weixin_post($url, $data) {
        $header = array(
            "Host:mp.weixin.qq.com",
            "Connection: keep-alive",
            "Origin: http://mp.weixin.qq.com",
            "X-Requested-With: XMLHttpRequest",
            "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
            "Accept: */*",
            "Referer: http://mp.weixin.qq.com/profile?src=3&timestamp=1494473102&ver=1&signature=5ANAj3eXwUD5KImAqpqhfnnzIx49V9*lzIc-MKxq21Ub-*QNcAut4VX*6Bjir*4pV91EPd5a36KhyOUN7hag*w==",
            "Accept-Encoding: gzip, deflate",
            "Accept-Language: zh-CN,zh;q=0.8",
            "Cookie: tvfe_boss_uuid=eacecb8ba8406795; pac_uid=1_1101205236; eas_sid=Y1s4f8G2e8I8d7h2Y8V0L8a4n9; pgv_pvi=2448154624; RK=F6P2mGG6e+; o_cookie=1101205236; pgv_pvid=2636863068; dm_login_weixin_rem=; logout_page=dm_loginpage; ptcz=ef796d67f24df4274a4ab9792bbf1db045da8929174caf6f4376cef068e29a43; pt2gguin=o1101205236; sig=h0187ebb888f9c9d2beb04ef40a269d05b36a632265076e19a4f033642b4b417239684eb28b35ba6986",
        );
        return $this->curl_get($url, $header, $data);
    }

    function verify_code_image() {
        #获取验证码图片
        $cert = time() + rand(1, 10000)/10000;
        $code_image = "http://mp.weixin.qq.com/mp/verifycode?cert={$cert}";
        $code_image_cache_dir = "./cache/images/code/";
        $this->dir_check($code_image_cache_dir);
        $image_cache_name = "verify.jpg";
        file_put_contents($code_image_cache_dir.$image_cache_name, file_get_contents($code_image));
        $result_json = $this->jsdati_upload("./cache/images/code/verify.jpg", 0, 4, 4);
        $dati_result = json_decode($result_json);
        if($dati_result->result) {
            $image_text = $dati_result->data->val;
            $weixin_verify_url = "http://mp.weixin.qq.com/mp/verifycode";
            $post_data = array(
                "cert" => $cert,
                "input" => $image_text
            );
            return $this->weixin_post($weixin_verify_url, http_build_query($post_data));
        }
        return false;
    }

}

set_time_limit(10000);
ignore_user_abort(false);

$wechat_id = "WebNotes";
$feeder = new Feeder();
echo "rss地址：".$feeder->generate_rss_address($wechat_id);

