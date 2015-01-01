<?php

namespace peanut\template {

    class exception extends \exception
    {

    }

}

namespace peanut {

    class template
    {
        public $compile_check=true;
        public $tpl_; // = [];
        public $var_; // = [];
        public $skin;
        public $tplPath;
        public $permission  = 0777;
        public $phpengine   = true;
        public $relativePath= [];

        public function __construct()
        {

            $this->tpl_ = [];
            $this->var_ = [];

        }

        public function assign($key, $value = FALSE)
        {

            if (TRUE === is_array($key))
            {
                $this->var_ = array_merge($this->var_, $key);
            }
            else
            {
                $this->var_[$key] = $value;
            }

        }

        public function define($fid, $path = FALSE)
        {

            if(TRUE === is_array($fid))
            {
                foreach ($fid as $subFid => $subPath)
                {
                    $this->_define($subFid, $subPath);
                }
            }
            else
            {
                $this->_define($fid, $path);
            }

        }

        private function _define($fid, $path)
        {

            $this->tpl_[$fid] = $path;

        }

        public function show($fid, $print = FALSE)
        {

            if (TRUE === $print)
            {
                $this->render($fid);
            }
            else
            {
                return $this->fetched($fid);
            }

        }

        public function fetched($fid)
        {

            ob_start();
            $this->render($fid);
            $fetched = ob_get_contents();
            ob_end_clean();

            return $fetched;

        }

        public function render($fid)
        {

            // define 되어있으나 값이 없을때
            if(TRUE === isset($this->tpl_[$fid]) && !$this->tpl_[$fid])
            {
                return;
            }

            $this->requireFile($this->getCompilePath($fid));

            return;

        }

        private function getCompilePath($fid)
        {

            $tplPath = $this->tplPath($fid);
            $cplPath = $this->cplPath($fid);

            if (!$this->compile_check)
            {
                return $cplPath;
            }

            if (@!is_file($tplPath))
            {
                throw new template\exception('cannot find defined template <b>'.$tplPath.'</b>');
            }

            $cpl_head = '<?php /* vendor\view\template '.date('Y/m/d H:i:s', filemtime($tplPath)).' '.$tplPath.' ';

            if ($this->compile_check!=='dev' && @is_file($cplPath))
            {

                $fp=fopen($cplPath, 'rb');
                $head = fread($fp, strlen($cpl_head)+9);
                fclose($fp);

                if (strlen($head)>9
                    && $cpl_head == substr($head,0,-9)
                    && filesize($cplPath) == (int)substr($head,-9) )
                {
                    return $cplPath;
                }
            }

            require_once __DIR__."/template/compiler.php";
            $compiler = new template\compiler();
            $compiler->execute($this, $fid, $tplPath, $cplPath, $cpl_head);

            return $cplPath;

        }

        private function requireFile($tplPath)
        {

            extract($this->var_);
            require $tplPath;

        }

        public function cplPath($fid)
        {

            return $this->compile_root.DIRECTORY_SEPARATOR.$this->relativePath[$fid];

        }

        public function tplPath($fid)
        {

            $path = $addFolder = "";

            if (TRUE === isset($this->tpl_[$fid]))
            {
                $path = $this->tpl_[$fid];
            }
            else
            {
                throw new template\exception($fid . "이(가) 정의되어있지 않음");
            }
            if (substr($path, 0, 1) != "/")
            {
                $skinFolder = trim($this->skin, "/");

                if ($skinFolder)
                {
                    $addFolder = $skinFolder . "/";
                }

                $this->relativePath[$fid] = $addFolder.$path;
                $tplPath = stream_resolve_include_path($addFolder.$path);
            }
            else
            {
                $tplPath = $path;
            }
            if (FALSE === is_file($tplPath))
            {
                throw new template\exception($fid . " 템플릿 파일이 없음 : " . $path);
            }
            return $this->tpl_[$fid] = $tplPath;
        }

    }

}
