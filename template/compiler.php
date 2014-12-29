<?php

namespace peanut\template;

class compiler
{
    private $brace      = [];
    private $loopkey    = 'A';
    private $permission = 0777;
    private $phpengine  = false;

    public function __construct() {
        $functions          = get_defined_functions();
        $this->all_functions= array_merge(
            $functions['internal'],
            $functions['user'],
            array('isset','empty','eval','list','array','include','require','include_once','require_once')
        );
    }

    public function execute($tpl, $fid, $tpl_path, $cpl_path, $cpl_head)
    {

        $this->permission   = $tpl->permission;
        $this->phpengine    = $tpl->phpengine;

        if (!@is_file($cpl_path))
        {

            $dirs = explode('/', $cpl_path);

            $path = '';
            $once_checked = false;

            for ($i=0, $s = count($dirs)-1; $i<$s; $i++)
            {

                $path .= $dirs[$i].'/';

                if ($once_checked or !is_dir($path) and $once_checked=true)
                {

                    if (false === mkdir($path))
                    {
                        throw new compiler\exception('cannot create compile directory <b>'.$path.'</b>');
                    }
                    @chmod($path, $this->permission);
                }
            }
        }

    // get template
        $source = '';
        if ($source_size = filesize($tpl_path))
        {
            $fp_tpl=fopen($tpl_path,'rb');
            $source=fread($fp_tpl,$source_size);
            fclose($fp_tpl);
        }

        $gt_than_or_eq_to_5_4 = defined('PHP_MAJOR_VERSION') and 5.4 <= (float)(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
        $php_tag = '<\?php|(?<!`)\?>';
        if (ini_get('short_open_tag')) $php_tag .= '|<\?(?!`)';
        elseif ($gt_than_or_eq_to_5_4) $php_tag .= '|<\?=';
        if (ini_get('asp_tags'))  $php_tag .= '|<%(?!`)|(?<!`)%>';
        $php_tag .= '|';


        $han = '/((?:<script\s+type\s*=\s*)(?:"text\/x\-handlebars\-template"|\'text\/x\-handlebars\-template\'|text\/x\-handlebars\-template)(?:.*)(?>\s*>))(.*)(<\/script>)/Usi';

        $handlebars = [];
        $i=0;
        $source = preg_replace_callback($han, function($matches) use (&$handlebars, &$i){
            $i++;
            $handlebars[$i] = $matches[0];
            return '###2413###'.$i.'###2413###';
        }, $source);

        $tokens=preg_split('/('.$php_tag.'<!--{{(?!`)|(?<!`)}}-->|{{(?!`)|(?<!`)}})/i', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

        $line       = 0;
        $is_open    = 0;
        $new_tokens = [];
        for ($_index=0,$s=count($tokens);$_index<$s;$_index++)
        {
            $line   = substr_count(implode("", $new_tokens), chr(10))+1;

            $new_tokens[$_index] = $tokens[$_index];

            switch (strtolower($tokens[$_index]))
            {
                case'<?php':
                case  '<?=':
                case   '<?':
                case   '<%':
                    if($this->phpengine == FALSE)
                    {
                        $new_tokens[$_index] = str_replace('<','&lt;',$tokens[$_index]);
                    }
                    else
                    {
                        $new_tokens[$_index] = $tokens[$_index];
                    }
                    break;
                case   '?>':
                case   '%>':
                    if($this->phpengine == FALSE)
                    {
                        $new_tokens[$_index] = str_replace('>','&gt',$tokens[$_index]);
                    }
                    else
                    {
                        $new_tokens[$_index] = $tokens[$_index];
                    }
                    break;
                case'<!--{{':
                case    '{{':
                    $is_open = $_index;
                    break;
                case '}}-->':
                case '}}'   :
                    if($is_open !== $_index-2)
                    {
                        break; // switch exit
                    }
                   // pr($tokens);
                    $result = $this->compileStatement($tokens[$_index-1], $line);
                    if($result[0] == 1)
                    {
                        $new_tokens[$_index-1] = $result[1];
                    }
                    else if($result[0] == 2)
                    {
                        $new_tokens[$is_open] = '<?php ';
                        $new_tokens[$_index-1] = $result[1];
                        $new_tokens[$_index] = '?>';
                    }
                    $is_open = 0;
                    break;
                default :
            }
        }

        if(count($this->brace))
        {
            array_pop($this->brace);
            $c = end($this->brace);
            throw new compiler\exception('error line '.$c[1]);
        }

        $source=implode("",$new_tokens);

        $han = '/###2413###([0-9]+)###2413###/';

        $source = preg_replace_callback($han, function($matches) use (&$handlebars){
            return $handlebars[$matches[1]];
        }, $source);

        $this->saveResult($cpl_path, $source, $cpl_head, '*/ ?>');

    }


    private function saveResult($cpl_path, $source, $cpl_head, $init_code)
    {

        $source_size = strlen($cpl_head)+strlen($init_code)+strlen($source) + 9;

        $source = $cpl_head.str_pad($source_size, 9, '0', STR_PAD_LEFT).$init_code.$source;

        $fp_cpl=fopen($cpl_path, 'wb');
        if (false===$fp_cpl)
        {
            throw new compiler\exception('cannot write compiled file "<b>'.$cpl_path.'</b>"');
        }
        fwrite($fp_cpl, $source);
        fclose($fp_cpl);

        if (filesize($cpl_path) != strlen($source))
        {

            @unlink($cpl_path);
            throw new compiler\exception('Problem by concurrent access. Just retry after some seconds. "<b>'.$cpl_path.'</b>"');

        }

    }

    public function compileStatement($statement,$line)
    {

        $statement = trim($statement);

        $match=[];
        preg_match('/^(\\\\*)\s*(:\?|\/@|\/\?|[=#@?:\/+])?(.*)$/s', $statement, $match);

        if($match[1])
        { // escape
            $result = [1,substr($statement,1)];
        } else {
            switch($match[2])
            {
                case  '@' :
                    $this->brace[] = ['if', $line];
                    $this->brace[] = ['loop', $line];
                    $result = [2, $this->compileLoop  ($statement,$line)];
                    break;
                case  '#' :
                    $result = [2, $this->compileDefine($statement,$line)];
                    break;
                case  ':' :
                    if(!count($this->brace)) {
                        throw new compiler\exception('error line '.$line);
                    }
                    $result = [2, $this->compileElse  ($statement,$line)];
                    break;
                case  '/?' :
                    if(!count($this->brace)) {
                        throw new compiler\exception('not if error line '.$line);
                    }
                    array_pop($this->brace);
                    array_pop($this->brace);

                    $result = [2, $this->compileCloseIf($statement,$line)];
                    break;

                case  '/@' :
                    if(!count($this->brace)) {
                        throw new compiler\exception('not loop error line '.$line);
                    }
                    array_pop($this->brace);
                    array_pop($this->brace);

                    $result = [2, $this->compileCloseLoop ($statement,$line)];
                    break;

                case  '/' :
                    if(!count($this->brace)) {
                        throw new compiler\exception('not if/loop error line '.$line);
                    }
                    array_pop($this->brace);
                    array_pop($this->brace);

                    $result = [2, $this->compileClose ($statement,$line)];
                    break;
                case  '=' :
                    $result = [2, $this->compileEcho  ($statement,$line)];
                    break;
                case  '?' :
                    $this->brace[] = ['if', $line];
                    $this->brace[] = ['if', $line];
                    $result = [2, $this->compileIf    ($statement,$line)];
                    break;
                case ':?' :
                    if(!count($this->brace)) {
                        throw new compiler\exception('error line '.$line);
                    }
                //    $this->brace[] = ['elseif', $line];
                //    $this->brace[] = ['if', $line];
                    $result = [2, $this->compileElseif($statement,$line)];
                    break;
                default :
                    $result = [2, $this->compileDefault($statement,$line)];
                    break;
            }
        }

        return $result;

    }

    public function compileDefine($statement,$line)
    {

        return "echo self::show('".substr($statement, 1)."')";

    }

    public function compileDefault($statement,$line)
    {

        return $this->tokenizer($statement,$line).';';

    }

    public function compileLoop($statement,$line)
    {

        $tokenizer = explode("=",$this->tokenizer(substr($statement,1),$line),2);

        if(isset($tokenizer[0]) == FALSE || isset($tokenizer[1]) == FALSE)
        {
            throw new compiler\exception('Parse error: syntax error, loop는 {{@row = array}}...{{/?}} 로 사용해주세요. line '.$line);
        }
        list($loop, $array) = $tokenizer;

        $loopValueName  = trim($loop);
        $loopKey        = $this->loopkey++;
        $loopArrayName  = '$_a'.$loopKey;
        $loopIndexName  = '$_i'.$loopKey;
        $loopSizeName   = '$_s'.$loopKey;
        $loopKeyName    = '$_k'.$loopKey;
        $loop_ValueName  = '$_j'.$loopKey;

        return
            $loopArrayName.'='.$array.';'
            .$loopIndexName.'=-1;'
            .'if(is_array('.$loopArrayName.')&&('.$loopSizeName.'=count('.$loopArrayName.'))'.'){'
                .'foreach('.$loopArrayName.' as '.$loopKeyName.'=>'.$loopValueName.'){'
                    .$loop_ValueName.'='.$loopValueName.';'
                    .'if(false == is_array('.$loopValueName.')) {'
                    .$loopValueName.'=(array)'.$loopValueName.';'
                    .'}'
                    .$loopIndexName.'++;'
                    .$loopValueName.'[\'index_\']='.$loopIndexName.';'
                    .$loopValueName.'[\'size_\']='.$loopSizeName.';'
                    .$loopValueName.'[\'key_\']='.$loopKeyName.';'
                    .$loopValueName.'[\'value_\']='.$loop_ValueName.';'
                    .$loopValueName.'[\'last_\']=('.$loopValueName.'[\'size_\']=='.$loopValueName.'[\'index_\']+1);';

    }

    public function compileIf($statement,$line)
    {

        return 'if('.$this->tokenizer(substr($statement, 1),$line).'){{';

    }

    public function compileEcho($statement,$line)
    {

        return 'echo '.$this->tokenizer(substr($statement, 1),$line).';';

    }

    public function compileElse($statement,$line)
    {

        return '}}else{{'.$this->tokenizer(substr($statement, 1),$line);

    }

    public function compileElseif($statement,$line)
    {

        return '}}else if('.$this->tokenizer(substr($statement, 2),$line).'){{';

    }

    public function compileClose($statement,$line)
    {

        return '}}'.$this->tokenizer(substr($statement, 1),$line);

    }

    public function compileCloseIf($statement,$line)
    {

        return '}}'.$this->tokenizer(substr($statement, 2),$line);

    }

    public function compileCloseLoop($statement,$line)
    {

        return '}}'.$this->tokenizer(substr($statement, 2),$line);

    }

    public function tokenizer($source,$line)
    {

        $expression = $source;
        $token = [];
        for ($i=0; strlen($expression); $expression=substr($expression, strlen($m[0])),$i++)
        {  //

            preg_match('/^
            (:P<unknown>(?:\.\s*)+)
            |(?P<number>(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+\-]?\d+)?)
            |(?P<array_keyword>array)
            |(?P<assoc_array>=\>)
            |(?P<object_sign>-\>)
            |(?P<static_object_sign>::|\\\)
            |(?P<compare>===|!==|<<|>>|<=|>=|==|!=|&&|\|\|\<|\>)
            |(?P<assign>\=)
            |(?P<string_concat>\.)
            |(?P<left_parenthesis>\()
            |(?P<right_parenthesis>\))
            |(?P<left_bracket>\[)
            |(?P<right_bracket>\])
            |(?P<comma>,)
            |(?:(?P<string>[A-Z_a-z\x7f-\xff][\w\x7f-\xff]*)\s*)
            |(?<quote>(?:"(?:\\\\.|[^"])*")|(?:\'(?:\\\\.|[^\'])*\'))
            |(?P<double_operator>\+\+|--)
            |(?P<operator>\+|\-|\*|\/|%|&|\^|~|\!|\|)
            |(?P<not_support>\?|:)
            |(?P<whitespace>\s+)
            |(?P<dollar>\$)
            |(?P<not_match>.+)
            /ix', $expression, $m);

            $r = ['org' => '', 'name' => '', 'value' => ''];
            foreach($m as $key => $value)
            {
                if(is_numeric($key))
                {
                    continue;
                }
                if(strlen($value))
                {
                    $v = trim($value);
                    if($key == 'number' && $v[0] == '.')
                    {
                        $token[] = ['org'=>'.','name'=>'number_concat','value' => '.'];
                        $r = ['org'=>substr($v,1),'name'=>'string_number','value' => substr($v,1)];
                    }
                    else
                    {
                        $r = ['org'=>$m[0],'name'=>$key,'value' => $v];
                    }
                    break;
                }
            }
            if($r['name'] != 'whitespace' && $r['name'] != 'enter')
            {
                $token[] = $r;
            }
        }

        $xpr    = '';
        $stat   = [];
        $assign = 0;
        $org    = '';
        foreach($token as $key => &$current)
        {
            $current['key'] = $key;
            if(isset($token[$key-1]))
            {
                $prev = $token[$key-1];
            }
            else
            {
                $prev = ['org' => '', 'name' => '', 'value' => ''];;
            }
            $org .= $current['org'];

            if(isset($token[$key+1]))
            {
                $next = $token[$key+1];
            }
            else
            {
                $next = ['org' => '', 'name' => '', 'value' => ''];
                // 마지막이 종결되지 않음
                if(!$next['name'] && !in_array($current['name'], ['string','number','string_number', 'right_bracket', 'right_parenthesis','double_operator','quote']))
                {
                    throw new compiler\exception('parse error : line '.$line.' '.$current['org']);
                }
            }

            switch($current['name'])
            {
                case 'string' :
                    if(!in_array($prev['name'], ['', 'left_parenthesis','left_bracket','assign','object_sign','static_object_sign','double_operator','operator','assoc_array', 'compare','quote_number_concat', 'assign', 'string_concat','comma']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        // 클로저를 허용하지 않음. 그래서 string_concat 비교 보다 우선순위가 높음
                        if(in_array($next['name'], ['left_parenthesis','static_object_sign']))
                        {
                            if($prev['name'] == 'string_concat')
                            {
                               throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org'].$next['org']);
                            }
                            else
                            {
                                if($current['value'] == '_')
                                {
                                    //$xpr .= '\\limepie\\'.$current['value'];
                                    $xpr .= $current['value'];
                                }
                                else
                                {
                                    $xpr .= $current['value'];
                                }
                            }
                        }
                        else if($prev['name'] == 'object_sign')
                        {
                            $xpr .= $current['value'];
                        }
                        else if($prev['name'] == 'static_object_sign')
                        {
                            $xpr .= '$'.$current['value'];
                        }
                        else if($prev['name'] == 'string_concat')
                        {
                            $xpr .= '[\''.$current['value'].'\']';
                        }
                        else
                        {
                            if(in_array($current['value'], ['true','false','null']))
                            {
                                $xpr .= $current['value'];
                            }
                            else if(preg_match('#__([a-zA-Z_]+)__#', $current['value']))
                            {
                                $xpr .= $current['value'];// 처음
                            }
                            else
                            {
                                $xpr .= '$'.$current['value'];// 처음
                            }
                        }
                    }
                    break;
                case 'dollar' :
                    throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    break;
                case 'not_support' :
                    throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    break;
                case 'not_match' :
                    throw new compiler\exception('parse error : line '.$line.' '.$current['org']);
                    break;
                case 'assoc_array' :
                    $last_stat = array_pop($stat);
                    if($last_stat
                        && $last_stat['key'] > 0
                        && in_array($token[$last_stat['key']-1]['name'],[ 'string'])
                    )
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    $stat[] = $last_stat;

                    if(!in_array($prev['name'], ['number', 'string', 'quote','right_parenthesis', 'right_bracket']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'quote' :
                    if(!in_array($prev['name'], ['','left_parenthesis', 'left_bracket','comma','compare','assoc_array','operator','quote_number_concat']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'number' :
                    $last_stat = array_pop($stat);
                    if($prev['name'] == 'assoc_array')
                    {

                    }
                    else if($last_stat
                        && $last_stat['key']>1
                        && $prev['name'] == 'assoc_array'
                        && !in_array($token[$last_stat['key']-1]['name'],[ 'left_bracket'])
                    )
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    $stat[] = $last_stat;

                    if(!in_array($prev['name'], ['','left_bracket','left_parenthesis', 'comma', 'compare', 'operator', 'assign','assoc_array','string', 'right_bracket','number_concat','string_concat','quote_number_concat'])) {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        if($prev['name'] == 'quote_number_concat')
                        {
                            $xpr .= "'".$current['value']."'" ;
                            $current['name'] = 'quote';
                        }
                        else if(in_array($prev['name'], ['string','right_bracket','number_concat']))
                        {
                            $xpr .= '['.$current['value'].']';
                        }
                        else
                        {
                            $xpr .= $current['value'] ;
                        }
                    }
                    break;
                case 'string_number' :
                    if(!in_array($prev['name'], ['right_bracket','number_concat']))
                    {//'string',
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= '['.$current['value'].']';
                    }
                    break;
                case 'number_concat' :
                    if(!in_array($prev['name'], ['string', 'string_number','right_bracket']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                    }
                    break;
                case 'double_operator' :
                    if(!in_array($prev['name'], ['string','number','string_number','assign']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'object_sign' :
                    if(!in_array($prev['name'], ['string','right_parenthesis']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'static_object_sign' :
                    if(!in_array($prev['name'], ['string','']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'operator' :
                    if(!in_array($prev['name'], ['','right_parenthesis','right_bracket', 'number', 'string', 'string_number', 'quote', 'assign']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        // + 이지만 앞이나 뒤가 quote라면 + -> .으로 바꾼다. 지금의 name또한 변경한다.
                        if($current['value'] == '+' && ($prev['name'] == 'quote' || $next['name'] == 'quote'))
                        {
                            $xpr .= ".";
                            $current['name'] = 'quote_number_concat';
                        }
                        else
                        {
                            $xpr .= $current['value'] ;
                        }
                    }
                    break;
                case 'compare' :
                    if(!in_array($prev['name'], ['number', 'string', 'string_number', 'assign', 'left_parenthesis', 'left_bracket','quote', 'right_parenthesis']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'assign' :
                    $assign ++;

                    if($assign > 1)
                    {
                        // $test = $ret = ... 와 같이 여러 변수를 사용하지 못하는 제약 조건
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else if(!in_array($prev['name'], ['right_bracket','string', 'operator']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        // = 앞에는 일부의 연산자만 허용된다. +=, -=...
                        if($prev['name'] == 'operator' && !in_array($prev['value'], ['+','-', '*', '/', '%','^', '!']))
                        {
                            throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                        }
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'left_bracket' :
                    $stat[] = $current;
                    if(!in_array($prev['name'], ['', 'assign','left_bracket','right_bracket', 'comma','left_parenthesis','string','string_number']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'right_bracket' :
                    $last_stat = array_pop($stat);
                    if($last_stat['name'] != 'left_bracket')
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    if(!in_array($prev['name'], ['quote', 'left_bracket','right_parenthesis', 'string', 'number','string_number', 'right_bracket']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'array_keyword' :
                    if(!in_array($prev['name'], ['','compare', 'operator','left_parenthesis','left_bracket','comma','assign']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'left_parenthesis' :
                    $stat[] = $current;
                    if(!in_array($prev['name'], ['','quote_number_concat', 'operator','compare','assoc_array','left_parenthesis','left_bracket','array_keyword', 'string','assign']))
                    {//, 'string_number' ->d.3.a() -> ->d[3]['a']() 제외
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'right_parenthesis' :
                    $last_stat = array_pop($stat);
                    if($last_stat['name'] != 'left_parenthesis')
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    if(!in_array($prev['name'], ['left_parenthesis','right_bracket','right_parenthesis','string', 'number','string_number','quote']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
                case 'comma' :
                    $last_stat = array_pop($stat);

                    if($last_stat['name'] && $last_stat['name'] == 'left_bracket' && $last_stat['key'] > 0)
                    {
                        // ][ ,] 면 배열키이므로 ,가 있으면 안됨
                        if(in_array($token[$last_stat['key']-1]['name'],[ 'right_bracket', 'string']))
                        {
                            throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                        }
                    }
                    // 배열이나 인자 속이 아니면 오류
                    if(!in_array($last_stat['name'], ['left_parenthesis', 'left_bracket']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    $stat[] = $last_stat;
                    if(!in_array($prev['name'], ['quote', 'string', 'number','string_number', 'right_parenthesis', 'right_bracket']))
                    {
                        throw new compiler\exception('parse error : line '.$line.' '.$prev['org'].$current['org']);
                    }
                    else
                    {
                        $xpr .= $current['value'] ;
                    }
                    break;
            }
        }

        if(count($stat))
        {
            $last_stat = array_pop($stat);
            if($last_stat['name'] == 'left_parenthesis')
            {
                throw new compiler\exception('parse error : line '.$line.' '.$current['org']);
            }
            else if($last_stat['name'] == 'left_bracket')
            {
                throw new compiler\exception('parse error : line '.$line.' '.$current['org']);
            }
        }
        return $xpr;

    }

}

namespace peanut\template\compiler;

class exception extends \exception
{

}
