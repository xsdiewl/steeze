<?php
namespace Library;

class Request{
	private $route=null; //路由对象
	private $request=null; //外部Request对象
    
    private $headers=null; //Header信息
    private $servers=null; //Server信息
	
	public function __construct(){
		
		if(get_magic_quotes_gpc()){
			$_POST=slashes($_POST, 0);
			$_GET=slashes($_GET, 0);
			$_REQUEST=slashes($_REQUEST, 0);
			$_COOKIE=slashes($_COOKIE, 0);
		}
		
		//session_id设置，防止客户端不支持cookie设定
		if($sessionid=$this->get('PHPSESSID',$this->post('PHPSESSID'))){
			session_id($sessionid);
		}
		
	}
	
	/**
	 * 动态方法调用
	 * @param string $name 方法名称
	 * @param mixed $args 参数
	 * 目前支持客户端请求方法判断：
	 * isGet()、isPost()、isDelete()、isPut()、分别用于判断GET、POST、DELETE、PUT请求方法
	 * 同时支持特定客户端请求判断：isAjax()、isWechat()、isMobile()
	 */
	public function __call($name,$args){
		if(stripos($name, 'is')===0){
			$method=strtoupper(substr($name, 2));
			$suppors=['GET','POST','DELETE','PUT','AJAX','WECHAT','MOBILE'];
			if(in_array($method, $suppors)){
				switch ($method){
					case 'AJAX': //判断ajax请求
						return env('IS_AJAX');
					case 'WECHAT': //判断微信客户端登录
						$user_agent=$this->server('user_agent');
						return isset($user_agent) && strpos($user_agent,'MicroMessenger')!==false;
					case 'MOBILE':
						$headers=$this->header();
				        $all_http=isset($headers['all-http']) ? $headers['all-http'] : '';
				        $mobile_browser=0;
				        $agent=isset($headers['user-agent']) ? strtolower($headers['user-agent']):'';
				        
				        if($agent && preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|iphone|ipad|ipod|android|xoom)/i',$agent)){
				            $mobile_browser++;
				        }elseif( (isset($headers['accept'])) && (strpos(strtolower($headers['accept']),'application/vnd.wap.xhtml+xml')!==false)){
				            $mobile_browser++;
				        }elseif(isset($headers['x-wap-profile'])){
				            $mobile_browser++;
				        }elseif(isset($headers['profile'])){
				            $mobile_browser++;
				        }elseif($agent){
				            $mobile_ua=substr($agent,0,4);
				            $mobile_agents=array(
				                    'w3c ','acs-','alav','alca','amoi','audi','avan','benq','bird','blac',
				                    'blaz','brew','cell','cldc','cmd-','dang','doco','eric','hipt','inno',
				                    'ipaq','java','jigs','kddi','keji','leno','lg-c','lg-d','lg-g','lge-',
				                    'maui','maxo','midp','mits','mmef','mobi','mot-','moto','mwbp','nec-',
				                    'newt','noki','oper','palm','pana','pant','phil','play','port','prox',
				                    'qwap','sage','sams','sany','sch-','sec-','send','seri','sgh-','shar',
				                    'sie-','siem','smal','smar','sony','sph-','symb','t-mo','teli','tim-',
				                    'tosh','tsm-','upg1','upsi','vk-v','voda','wap-','wapa','wapi','wapp',
				                    'wapr','webc','winw','winw','xda','xda-'
				            );
				            if(in_array($mobile_ua,$mobile_agents)){
				                $mobile_browser++;
				            }elseif(strpos(strtolower($all_http),'operamini')!==false){
				                $mobile_browser++;
				            }
				        }
				        
				        if(strpos($agent,'windows')!==false){
				            $mobile_browser=0;
				        }
				        if(strpos($agent,'windows phone')!==false){
				            $mobile_browser++;
				        }
				        return $mobile_browser>0;

					default: //请求方法判断
						return env('REQUEST_METHOD')==$method;
				}
			}
		}
		return false;
	}
	
	/**
	 * 设置外部请求对象
	 * @param Request $request 外部响应对象
	 */
	public function setRequest($request){
        $this->headers=null;
		if(!empty($request) && is_a($request,'Swoole\\Http\\Request')){
			$this->request=$request;
            if(isset($request->get)){
			    $_GET=&$request->get;
            }
            if(isset($request->post)){
			    $_POST=&$request->post;
            }
            if(isset($request->files)){
                $_FILES=&$request->files;
            }
            if(isset($request->cookie)){
                $_COOKIE=&$request->cookie;
            }
            $_SERVER=$this->restoreServer($request->server, $request->header);
            $this->servers=array_change_key_case($request->server, CASE_LOWER);
		}else if(PHP_SAPI=='cli'){
            $_GET=$_POST=$_REQUEST=$_FILES=$_COOKIE=[];
            $this->servers=array_change_key_case($_SERVER, CASE_LOWER);
        }else{
            $this->servers=array_change_key_case($_SERVER, CASE_LOWER);
        }
	}
	
	/**
	 * 获取Http请求的头部信息（键名为小写）
	 * @param string|null|array $key 需要获取的键名，如果为null获取所有，如果为数组则重置请求头部
	 * @param mixed $default 如果key不存在，则返回默认值
	 * @return string|array 
	 */
	public function &header($key=null,$default=null){
        if(is_null($this->headers)){
            $this->headers=array_change_key_case(!is_null($this->request) ? $this->request->header : $this->getAllHeaders(), CASE_LOWER);
        }
        if(!is_null($key)){
			$key=strtolower($key);
            if(isset($this->headers[$key])){
                return $this->headers[$key];
            }
			return $default;
		}
		return $this->headers;
	}
	
	/**
	 * 获取Http请求相关的服务器信息（不区分大小写）
	 * @param string $key 需要获取的键名，如果为null获取所有
	 * @param mixed $default 如果key不存在，则返回默认值
	 * @return string|array 
	 */
	public function &server($key=null,$default=null){
        if(!is_null($key)){
			$key=strtolower($key);
            if(isset($this->servers[$key])){
                return $this->servers[$key];
            }
			return $default;
		}
		return $this->servers;
	}
	
	/**
	 * 获取Http请求的GET参数
	 * @param string $key 需要获取的键名，如果为null获取所有
	 * @param mixed $default 如果key不存在，则返回默认值
	 * @return string|array 
	 */
	public function &get($key=null,$default=null){
		if(!is_null($key)){
            if(isset($_GET[$key])){
                return $_GET[$key];
            }
            return $default;
        }
		return $_GET;
	}
	
	/**
	 * 获取Http请求的POST参数
	 * @param string $key 需要获取的键名，如果为null获取所有
	 * @param mixed $default 如果key不存在，则返回默认值
	 * @return string|array
	 */
	public function &post($key=null,$default=null){
        if(!is_null($key)){
            if(isset($_POST[$key])){
                return $_POST[$key];
            }
            return $default;
        }
		return $_POST;
	}
	
	/**
	 * 获取Http请求携带的COOKIE信息
	 * @param string $key 需要获取的键名，如果为null获取所有
	 * @param mixed $default 如果key不存在，则返回默认值
	 * @return string|array
	 */
	public function &cookie($key=null,$default=null){
        if(!is_null($key)){
            if(isset($_COOKIE[$key])){
                return $_COOKIE[$key];
            }
            return $default;
        }
		return $_COOKIE;
	}
	
	/**
	 * 获取文件上传信息
	 * @param string $key 需要获取的键名，如果为null获取所有
	 * @return array
	 */
	public function &files($key=null){
        if(!is_null($key)){
            return $_FILES[$key];
        }
		return  $_FILES;
	}
	
	/**
	 * 获取原始的POST包体
	 * @return mixed 返回原始POST数据
	 * 说明：用于非application/x-www-form-urlencoded格式的Http POST请求
	 * */
	public function rawContent(){
        return !is_null($this->request)  ?   
                    $this->request->rawContent() : 
                    (PHP_SAPI=='cli' ? 
                        $GLOBALS['HTTP_RAW_POST_DATA'] : 
                        file_get_contents('php://input')
                    );
	}
	
	/**
	 * 设置路由对象
	 * @param Route $route 路由对象
	 */
	public function setRoute(Route $route){
		return $this->route=$route;
	}
	
	/**
	 * 获取路由对象
	 * @return Route $route 路由对象
	 */
	public function getRoute(){
		return $this->route;
	}
	
	/**
	 * 获取系统运行模式 
	 */
	public function getSapiName(){
		return !is_null($this->request) ? 'swoole' : PHP_SAPI;
	}

	/**
	 * 获取所有header信息
	 * @return array
	 */
	private function getAllHeaders(){
		$headers=array();
		foreach($this->servers as $key=>$value){
			if('http_' == substr($key, 0, 5)){
				$headers[str_replace('_', '-', substr($key, 5))]=$value;
			}
		}
		if(isset($this->servers['php_auth_digest'])){
			$headers['authorization'] = $this->servers['php_auth_digest'];
		}else if(isset($this->servers['php_auth_user']) && isset($this->servers['php_auth_pw'])){
			$headers['authorization'] = base64_encode($this->servers['php_auth_user'] . ':' . $this->servers['php_auth_pw']);
		}
		
		if(isset($this->servers['content_length'])){
			$headers['content-length']=$this->servers['content_length'];
		}
		if(isset($this->servers['content_type'])){
			$headers['content-type']=$this->servers['content_type'];
		}
		return $headers;
	}
    
    /**
     * 从Swoole执行环境中恢复$_SERVER全局变量
     *
     * @param array $server Swoole环境中的server变量
     * @param array $header Swoole环境中的header变量
     * @return array
     */
    private function restoreServer($server, $header){
        $servers=array_change_key_case($server, CASE_UPPER);
        foreach ($header as $key => $value) {
            $newKey='HTTP_'.strtoupper(str_replace('-', '_', $key));
            $servers[$newKey]=$value;
        }
        return $servers;
    }
	
}
