<?php
namespace Service\Template;
use Library\Container;

// 模版服务
class Manager {
	private $leftDelim; // 模版标签左边界字符
	private $rightDelim;
	private static $_instance=null;
	
	// 单例模式
	public static function instance(){
		if(is_null(self::$_instance)){
			self::$_instance=new static();
		}
		return self::$_instance;
	}
	
	// 模版标签右边界字符
	public function __construct(){
		$this->leftDelim=defined('TAGLIB_BEGIN') ? TAGLIB_BEGIN : C('TAGLIB_BEGIN', '<');
		$this->rightDelim=defined('TAGLIB_END') ? TAGLIB_END : C('TAGLIB_END', '>');
	}
	
	public function set_delim($ldelim,$rdelim){
		$this->leftDelim=$ldelim;
		$this->rightDelim=$rdelim;
	}
	
	/**
	 * 模版编译
	 *
	 * @param string $tplfile 模板文件路径
	 * @param string $compiledtplfile 编译后文件路径
	 * @return number 生成的编译文件的字节数
	 */
	public function compile($tplfile,$compiledtplfile){
		if(!is_file($tplfile)){
			E(L('template {0} is not exists',str_replace(KERNEL_PATH . 'template' . DS . 'styles' . DS, '', $tplfile)));
		}
		$content=file_get_contents($tplfile);
		$filepath=dirname($compiledtplfile) . DS;
		if(!is_dir($filepath)){
			mkdir($filepath, 0777, true);
		}
		$content=$this->parse($content);
		$strlen=file_put_contents($compiledtplfile, $content);
		chmod($compiledtplfile, 0777);
		return $strlen;
	}
	
	/**
	 * 更新模板缓存
	 *
	 * @param $tplfile 模板原文件路径
	 * @param $compiledtplfile 编译完成后，写入文件名
	 * @return $strlen 长度
	 */
	public function refresh($tplfile,$compiledtplfile){
		$str=file_get_contents($tplfile);
		$str=$this->parse($str);
		$strlen=file_put_contents($compiledtplfile, $str);
		chmod($compiledtplfile, 0777);
		return $strlen;
	}
	
	/**
	 * 解析模板
	 *
	 * @param string $str
	 * @return bool
	 */
	public function parse($str){
		$ld=$this->leftDelim;
		$rd=$this->rightDelim;
		$str=preg_replace('/\<\!--\s*%.*?%\s*--\>/is', '', $str);
		// 全局标签的解析
		stripos($str, $ld . 'import ') !== false && ($str=preg_replace_callback('/' . $ld . 'import\s+(.+?)\s*\/?' . $rd . '/is', array($this,'parseImport'), $str));
		(stripos($str, $ld . 'template ') !== false || stripos($str, $ld . 'include ') !== false) && ($str=preg_replace_callback('/' . $ld . '(?:template|include)\s+(.+?)\s*\/?' . $rd . '/is', array($this,'parseInclude'), $str));
		stripos($str, $ld . 'action ') !== false && ($str=preg_replace_callback('/' . $ld . 'action\s+(.+?)\s*\/?' . $rd . '/is', array($this,'parseAction'), $str));

		$str=preg_replace_callback('/' . $ld . '([a-z]\w*):([a-z]\w*)\s+([^'.$ld.$rd.']*)\s*\/' . $rd . '/is', array($this,'parseTagLib'), $str);
		$str=preg_replace_callback('/' . $ld . '([a-z]\w*):([a-z]\w*)\s+(.*?)\s*' . $rd . '(.*?)' . $ld . '\/\1:\2' . $rd . '/is', array($this,'parseTagLib'), $str);
		
		
		// PHP标签的解析
		if(stripos($str, $ld . 'php') !== false){
			// 单行PHP代码；php标签内不能带有'{'或'}'字符
			$str=preg_replace('/' . $ld . 'php\s+([^' . $ld . '' . $rd . ']+)\s*\/?' . $rd . '/is', '<?php \\1;?>', $str);
			// 多行PHP代码
			$str=str_ireplace($ld . 'php' . $rd, '<?php ', $str);
			$str=str_ireplace($ld . '/php' . $rd, ' ?>', $str);
		}
		
		// 条件标签的解析（支持“.”语法表示数组）
		stripos($str, $ld . 'if ') !== false && ($str=preg_replace_callback('/' . $ld . 'if\s+(.+?)\s*' . $rd . '/is', array($this,'parseIf'), $str));
		stripos($str, $ld . 'else') !== false && ($str=preg_replace('/' . $ld . 'else\s*\/?' . $rd . '/is', '<?php } else { ?>', $str));
		stripos($str, $ld . 'elseif ') !== false && ($str=preg_replace_callback('/' . $ld . 'elseif\s+(.+?)\s*\/?' . $rd . '/is', array($this,'parseElseif'), $str));
		stripos($str, $ld . '/if') !== false && ($str=str_ireplace($ld . '/if' . $rd, '<?php } ?>', $str));
		
		// ////////////循环的解析/////////////
		// for 循环
		if(stripos($str, $ld . 'for ') !== false){
			$str=preg_replace('/' . $ld . 'for\s+(.+?)' . $rd . '/is', '<?php for(\\1) { ?>', $str);
			$str=str_ireplace($ld . '/for' . $rd, '<?php } ?>', $str);
		}
		// loop 或 foreach循环（待输出的变量支持“.”语法表示数组）
		if(stripos($str, $ld . 'loop ') !== false || stripos($str, $ld . 'foreach ') !== false){
			$str=preg_replace_callback('/' . $ld . '(?:loop|foreach)\s+(.+?)' . $rd . '/is', array($this,'parseForeach'), $str);
			$str=preg_replace('/' . $ld . '\/(?:loop|foreach)' . $rd . '/is', '<?php } ?>', $str);
		}
		
		// ////////////变量、函数、常量、表达式的解析（取消对汉字[\x7f-\xff]变量或函数名的匹配）/////////////
		// 自增，自减变量的解析 ++ --
		$str=preg_replace('/{\+\+(.+?)}/', '<?php ++\\1; ?>', $str);
		$str=preg_replace('/{\-\-(.+?)}/', '<?php ++\\1; ?>', $str);
		$str=preg_replace('/{(.+?)\+\+}/', '<?php \\1++; ?>', $str);
		$str=preg_replace('/{(.+?)\-\-}/', '<?php \\1--; ?>', $str);
		
		// 函数调用解析（支持“.”语法表示数组）
		$str=preg_replace_callback('/{:?(([\$@]?[a-zA-Z_][a-zA-Z0-9_:]*)\(([^{}]*)\))}/', array($this,'parseFunc'), $str); // parse function or var function call like {date('Y-m-d',$r['addtime'])}
		// 变量输出（支持“.”语法表示数组）
		$str=preg_replace_callback('/{(\$?[a-zA-Z_][a-zA-Z0-9_\.]*)(\|[^{}]+)?}/', array($this,'parseVar'), $str); // parse pure var like {$a_b|md5}
		// 数组变量常规输出
		$str=preg_replace_callback('/{(\$[a-zA-Z0-9_\[\]\'\"\$]+)(\|[^{}]+)?}/s', array($this,'parseArray'), $str); // parse var array var like {$r['add']|md5}
		// 表达式解析（支持“.”语法表示数组）
		$str=preg_replace_callback('/{\((.+?)\)}/s', array($this,'parseExpression'), $str); // parse expression like "{(a=10?1:0)}"
		// 解析表达式转义字符
		$str=str_replace(array('\\{','\\}'), array('{','}'), $str);
		
		$str='<?php defined(\'INI_STEEZE\') or exit(\'No permission resources.\'); ?>' . $str;
		return $str;
	}
	
	/**
	 * 解析import标签
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseImport($matches){
		$arrs=$this->parseAttrs($matches[1]);
		$files=explode(',', isset($arrs['file']) ? $arrs['file'] : $matches[1]);
		$types=explode(',', isset($arrs['type']) ? $arrs['type'] : '');
		$checks=explode(',', isset($arrs['check']) ? $arrs['check'] : '');
		$defaults=explode(',', isset($arrs['default']) ? $arrs['default'] : 'default');
		$res='';
		foreach($files as $k=>$file){
			$type=isset($types[$k]) ? $types[$k] : $types[0];
			$check=isset($checks[$k]) ? $checks[$k] : $checks[0];
			$default=isset($defaults[$k]) ? $defaults[$k] : $defaults[0];
			
			$ext=$type !== '' ? $type : fileext(($pos=strpos($file, '@')) == false ? $file : substr($file, 0, $pos));
			$file=resx($file, $type, ($check != 'false' && $check), $default);
			if($file !== ''){
				switch($ext){
					case 'css':
						$res.='<link rel="stylesheet" type="text/css" href="' . $file . '" />' . "\r\n";
						break;
					case 'js':
						$res.='<script type="text/javascript" src="' . $file . '"></script>' . "\r\n";
						break;
					default:
						if($ext == 'jpg' || $ext == 'jpeg' || $ext == 'png' || $ext == 'gif' || $ext == 'bmp'){
							$image_attr='';
							foreach($arrs as $k=>$arr){
								if($k != 'file' && $k != 'type' && $k != 'check' && $k != 'default'){
									$image_attr.=' ' . $k . '="' . $arr . '"';
								}
							}
							$res.='<img src="' . $file . '"' . $image_attr . '/>' . "\r\n";
						}
						break;
				}
			}
		}
		return rtrim($res);
	}
	
	/**
	 * 解析include或template标签 用法：模块@主题风格/控制器/方法 例如：home@default/index/show
	 *
	 * @param array $matches
	 * @return string
	 */
	public function parseInclude($matches){
		$arrs=$this->parseAttrs($matches[1]);
		$res='';
		if(isset($arrs['file'])){
			$depr='/';
			$files=explode(',', $arrs['file']);
			$dtype=isset($arrs['type']) ? ($arrs['type'] != 'false' && $arrs['type'] ? 'true' : 'false') : false;
			foreach($files as $file){
				$m=($pos=strpos($file, '@')) ? substr($file, 0, $pos) : ROUTE_M;
				if($pos){
					$file=substr($file, $pos + 1);
				}
				$c=!defined('STWMS_TMPL_C') ? ROUTE_C : STWMS_TMPL_C;
				$a=!defined('STWMS_TMPL_A') ? ROUTE_A : STWMS_TMPL_A;
				$style='';
				if($file !== ''){
					$dpos=strpos($file, $depr);
					if($dpos === false){ // "a"
						$a=$file;
					}elseif($dpos === 0){
						$file=substr($file, 1);
						$dpos=strpos($file, $depr);
						if($dpos === false){ // "/a"
							$c='';
							$a=$file;
						}elseif(substr_count($file, $depr) > 1){ // eg:"/style/c/a"
							list($style, $c, $a)=explode($depr, $file);
						}else{ // eg:"/style/a"
							list($style, $a)=explode($depr, $file);
							$c='';
						}
					}elseif(substr_count($file, $depr) > 1){ // eg:"style/c/a"
						list($style, $c, $a)=explode($depr, $file);
					}else{ // eg:"c/a"
						list($c, $a)=explode($depr, $file);
					}
				}
				$paras=array('\'' . $a . '\'','\'' . $c . '\'','\'' . $style . '\'','\'' . $m . '\'');
				if($dtype){
					array_push($paras, $dtype);
				}
				$res.='include template(' . implode(',', $paras) . ');';
			}
		}else{
			$res.='include template(' . $matches[1] . ');';
		}
		
		return !empty($res) ? '<?php ' . $res . ' ?>' : '';
	}
	
	/**
	 * 解析action标签
	 * 
	 */
	public function parseAction($matches){
		$datas=$this->parseAttrs($matches[1]); // 获取属性
		$str='';
		$return=isset($datas['return']) && trim($datas['return']) ? str_replace('$', '', trim($datas['return'])) : '';
		if(isset($datas['name'])){
			$mcas=explode('.', $datas['name'],3);
			$action=array_pop($mcas);
			empty($mcas) && array_push($mcas, ROUTE_C);
			unset($datas['name']);
			$str.=(!empty($return) ? '$' . $return . ' = ' : '') . '\Library\Controller::run(\Loader::controller(\''.implode('.', $mcas).'\'),\'_'.$action.'\','.array2html($datas).');';
		}
		return !empty($str) ? '<?php ' . $str . ' ?>' : '';
	}
	
	/**
	 * 解析if标签
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseIf($matches){
		$arrs=$this->parseAttrs($matches[1]);
		$condition=self::operator(isset($arrs['condition']) ? $arrs['condition'] : $matches[1]);
		$condition=$this->changeDotArray($condition);
		return '<?php if(' . $condition . ') { ?>';
	}
	
	/**
	 * 解析elseif标签
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseElseif($matches){
		$arrs=$this->parseAttrs($matches[1]);
		$condition=self::operator(isset($arrs['condition']) ? $arrs['condition'] : $matches[1]);
		$condition=$this->changeDotArray($condition);
		return '<?php }elseif(' . $condition . ') { ?>';
	}
	
	/**
	 * 解析foreach标签
	 *
	 * @param array $matches
	 */
	public function parseForeach($matches){
		$arrs=$this->parseAttrs($matches[1]);
		if(empty($arrs)){
			if(preg_match('/(\S+)\s+(\S+)\s+(\S+)?/', $matches[1], $match)){
				$arrs['name']=$match[1];
				$arrs['key']=strpos($match[2], '$') !== 0 ? '$' . $match[2] : $match[2];
				$arrs['item']=strpos($match[3], '$') !== 0 ? '$' . $match[3] : $match[3];
			}elseif(preg_match('/(\S+)\s+(\S+)?/', $matches[1], $match)){
				$arrs['name']=$match[1];
				$arrs['item']=strpos($match[2], '$') !== 0 ? '$' . $match[2] : $match[2];
			}
		}else{
			isset($arrs['name']) && strpos($arrs['name'], '$') !== 0 && preg_match('/^\w+$/i', $arrs['name']) && ($arrs['name']='$' . $arrs['name']);
			isset($arrs['item']) && strpos($arrs['item'], '$') !== 0 && ($arrs['item']='$' . $arrs['item']);
			isset($arrs['key']) && strpos($arrs['key'], '$') !== 0 && ($arrs['key']='$' . $arrs['key']);
			isset($arrs['order']) && strpos($arrs['order'], '$') !== 0 && ($arrs['order']='$' . $arrs['order']);
		}
		if(isset($arrs['name']) && isset($arrs['item'])){
			$arrs['name']=$this->changeDotArray($arrs['name']);
			$order=(isset($arrs['order']) ? $arrs['order'] : '$n');
			return '<?php ' . $order . '=0;if(is_array(' . $arrs['name'] . ')) foreach(' . $arrs['name'] . ' as ' . (isset($arrs['key']) ? $arrs['key'] . '=>' . $arrs['item'] : $arrs['item']) . ') { ' . $order . '++;?>';
		}
	}
	
	/**
	 * 解析数组
	 *
	 * @param array $matches
	 * @return string
	 */
	public function parseArray($matches){
		$var_str=preg_replace("/\[([a-zA-Z0-9_\-\.]+)\]/s", "['\\1']", $matches[1]);
		if(isset($matches[2])){
			$var_str=$this->parseVarFuncs($var_str, $matches[2]);
		}
		return '<?php echo ' . $var_str . ';?>';
	}
	
	/**
	 * 解析函数 支持以“.”分割的数组表示方式
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseFunc($matches){
		return '<?php echo ' . $this->changeDotArray($matches[1]) . ';?>';
	}
	
	/**
	 * 解析数组变量，包括“.”操作符号的数组变量
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseVar($matches){
		$var_str=$this->parseDoVar($matches[1]);
		if(isset($matches[2])){
			$var_str=$this->parseVarFuncs($var_str, $matches[2]);
		}
		return '<?php echo ' . $var_str . ';?>';
	}
	
	/**
	 * 解析表达式 支持以“.”分割的数组表示方式
	 *
	 * @param unknown $matches
	 * @return string
	 */
	public function parseExpression($matches){
		return '<?php echo (' . $this->changeDotArray($matches[1]) . ');?>';
	}
	
	/**
	 * 解析PC标签
	 *
	 * @param string $op 操作方式
	 * @param string $datas 参数
	 */
	public function parseTagLib($matches){
		static $tagCaches=[];
		$tag=ucfirst(strtolower($matches[1]));
		$method='parse'.ucfirst(strtolower($matches[2]));
		$container=Container::getInstance();
		
		if(!isset($tagCaches[$tag])){
			//先尝试从应用查找标签类
			if(defined('ROUTE_M')){
				try{
					$tagCaches[$tag]=$container->make('\\App\\'.ROUTE_M.'\\Taglib\\'.$tag);
				}catch (\Exception $e){}
			}
			
			//再从系统中查找标签类
			if(!isset($tagCaches[$tag])){
				try{
					$tagCaches[$tag]=$container->make('\\Service\\Template\\Taglib\\'.$tag);
				}catch (\Exception $e){
					$tagCaches[$tag]=false;
				}
			}
		}
		
		if(
			isset($tagCaches[$tag]) && is_object($tagCaches[$tag]) && 
			is_callable(array($tagCaches[$tag], $method))
		){
			return $container->callMethod($tagCaches[$tag], $method,[
						'attrs'=>$this->parseAttrs($matches[3]),
						'html'=>(isset($matches[4]) ? $matches[4] : ''),
					]);
		}else{
			return $matches[0];
		}
	}
	
	// //////////////////部分工具函数//////////////////
	
	/**
	 * 将用“.”表示的数组，转化为PHP表示
	 *
	 * @param string $str 需要转化的字符串
	 * @return string 转化后的字符串
	 */
	public function changeDotArray($var_str){
		if(strpos($var_str, '$') !== false){
			$is_esc=strpos($var_str, '\$') !== false;
			if($is_esc){
				$rep=':ESC' . substr(md5($var_str), 8, 16) . ':';
				$var_str=str_replace('\$', $rep, $var_str);
			}
			$var_str=preg_replace_callback('/(\$[a-zA-Z_][a-zA-Z0-9_]*(?:\.[\$a-zA-Z0-9_]+)+)/', array($this,'parseDoVar'), $var_str);
			if($is_esc){
				$var_str=str_replace($rep, '$', $var_str);
			}
		}
		return $var_str;
	}
	
	/**
	 * 解析使用点号分割的数组表示字符串
	 *
	 * @param unknown $para
	 * @return string 数组表示的字符串
	 */
	public function parseDoVar($para){
		$var_str='';
		foreach(explode('.', (is_array($para) ? $para[1] : $para)) as $ky=>$vl){
			if($vl !== ''){
				$var_str.=!$ky ? $vl : (strpos($vl, '$') === 0 ? '[' . $vl . ']' : '[\'' . $vl . '\']');
			}
		}
		return $var_str;
	}
	
	/**
	 * 解析多级函数调用的字符串表示形式， 例如：将{$addtime|date='Y-m-d','###'|md5}解析为<?php echo md5(date('Y-m-d',$addtime));?>
	 *
	 * @param unknown $var_str 变量的字符串表示
	 * @param unknown $funstr 函数列表
	 * @return string 函数调用表示的字符串
	 */
	private function parseVarFuncs($var_str,$funstr){
		$fns=explode('|', substr($funstr, 1));
		foreach($fns as $fn){
			if(preg_match('/^([\\$@]?[a-zA-Z_][a-zA-Z0-9_:]*)=(.+)/', $fn, $cmatches)){
				if(strpos($cmatches[2], '###') !== false){
					$var_str=str_replace('###', $var_str, $cmatches[2]);
				}else{
					$tmps=explode(',', $cmatches[2]);
					array_unshift($tmps, $var_str);
					$var_str=implode(',', $tmps);
				}
				$var_str=$cmatches[1] . '(' . $var_str . ')';
			}else{
				$var_str=$fn . '(' . $var_str . ')';
			}
		}
		return $var_str;
	}
	
	/**
	 * 从字符串中提取属性，并将所有的属性名称转化为小写
	 *
	 * @param string $str 包含键和属性的字符串
	 * @return multitype: 键和属性对应的数组
	 */
	private function parseAttrs($str){
		$isDq=strpos($str, '\"') !== false;
		$isSq=strpos($str, '\\\'') !== false;
		$spat='/(\w+)=\'([^\']+)\'/';
		$dpat='/(\w+)="([^"]+)"/';
		$str_md5=substr(md5($str), 9, 16);
		
		if($isDq){
			$rDtag=':DQ' . $str_md5 . ':';
			$str=str_replace('\"', $rDtag, $str);
		}
		
		if($isSq){
			$rStag=':SQ' . $str_md5 . ':';
			$str=str_replace('\\\'', $rStag, $str);
		}
		
		!preg_match_all($spat, $str, $matches) && preg_match_all($dpat, $str, $matches);
		!empty($matches[0]) && array_shift($matches);
		$isDq && $this->replace($rDtag, '\"', $matches);
		$isSq && $this->replace($rStag, '\\\'', $matches);
		foreach($matches[0] as $k=>$v){
			$matches[0][$k]=strtolower($v);
		}
		return array_combine($matches[0], $matches[1]);
	}
	
	/**
	 * 扩展替换函数，支持数组递归替换
	 *
	 * @param void $search 搜索的字符串
	 * @param void $replace 用于替换的字符串
	 * @param void $str 原字符串
	 * @return mixed 替换后的字符串
	 */
	private function replace($search,$replace,&$str){
		if(is_array($str)){
			foreach($str as $k=>$v){
				$str[$k]=$this->replace($search, $replace, $v);
			}
		}else if(is_string($str)){
			$str=str_replace($search, $replace, $str);
		}
		return $str;
	}
	
	/**
	 * 替换SQL字符串条件语句中的比较操作符
	 *
	 * @param string $str
	 * @return mixed
	 */
	public static function operator($sqlstr){
		$search=array(' eq ',' neq ',' gt ',' egt ',' lt ',' elt ',' heq ',' nheq ');
		$replace=array(' == ',' != ',' > ',' >= ',' < ',' <= ',' === ',' !== ');
		return str_replace($search, $replace, $sqlstr);
	}
}