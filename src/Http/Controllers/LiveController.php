<?php
namespace Jiny\WireTable\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Auth;

use Jiny\WireTable\Http\Controllers\BaseController;
class LiveController extends BaseController
{
    // 화면처리
    protected $viewFileLayout;
    protected $viewFileTable;
    protected $viewFileList;
    protected $viewFileItem;
    protected $viewFileTitle;
    protected $viewFileForms;

    protected $slot;
    protected $theme;


    protected $packageName = "jiny-wire-table";
    use \Jiny\WireTable\Http\Trait\Permit;
    //use \Jiny\Table\Http\Controllers\SetMenu;

    // 2단계 nested table data
    //protected $nested_id;
    protected $nested = [];

    public function __construct()
    {
        parent::__construct();
    }


    /**
     * index 뷰 리소스 파일을 설정합니다.
     */
    protected function setViewIndex($name)
    {
        $this->actions['view']['main'] = $name;
        return $this;
    }

    /**
     * CRUD Resource Process
     */
    public function index(Request $request)
    {
        // IP확인
        $ipAddress = $request->ip();
        $this->actions['request']['ip'] = $ipAddress;

        // request로 전달되는 uri 파라미터값을 분석합니다.
        $this->checkRequestNesteds($request);

        // request로 전달되는 uri 쿼리스트링을 확인합니다.
        $this->checkRequestQuery($request);

        // 라이브와이어로 전달될, Table Blade를 설정합니다.
        $this->setViewFileTable();


        // 테마확인
        if(isset($this->actions['theme'])) {
            if($this->actions['theme']) {
                if(function_exists("setTheme")) {
                    $this->actions['theme'] = str_replace('.',"/",$this->actions['theme']);
                    setTheme($this->actions['theme']);

                    // 레이아웃 적용을 테마로 설정합니다.
                    $this->viewFileLayout = $this->packageName."::theme.layout";
                }
            }
        }

        // 로그인: 사용자 메뉴 설정
        $user = Auth::user();
        if($user) {
            //$this->setUserMenu($user);
        }

        // 권한확인
        $this->permitCheck();
        if($this->permit['read']) {

            ## 테이블 레이아웃을 읽어 옵니다.
            $view = $this->getViewFileLayout();
            //dd($view);
            if (view()->exists($view)) {
                $_data = [
                    'actions'=>$this->actions,
                    'nested'=>$this->nested,
                    'request'=>$request
                ];
                return view($view,$_data);
            }

            ## 테이블 레이아웃 없는 경우
            return view($this->packageName."::errors.no_layout",[
                'message' => $view."를 읽어올수 없습니다.",
                'actions'=>$this->actions
            ]);
        }



        ## 권한 접속 실패
        ## 권환에 대한 오류 화면을 출력합니다.
        return view("jiny-wire-table::error.permit",[
            'actions'=>$this->actions,
            'request'=>$request
        ]);
    }


    protected function setViewFileTable()
    {
        if(isset($this->actions['view']['table'])) {
            return $this->actions['view']['table'];
        }

        // 사용자 테이블이 미설정 되어 있는 경우
        if($this->viewFileTable) {
            $this->actions['view']['table'] = $this->viewFileTable;
        }

        return "jiny-wire-table::table_popup_forms.table";
    }


    ## 인덱스의
    ## Layout view를 확인합니다.
    protected function getViewFileLayout()
    {
        // 우선순위1
        // actions 설정값
        if (isset($this->actions['view']['layout'])) {
            $viewFile = $this->actions['view']['layout'];
            if($result = $this->isExistView($viewFile)) {
                return $result;
            }

            // 테마 파일
            if($result = $this->inThemeView($viewFile)) {
                return $result;
            }

            if(View::exists($viewFile)) {
                return $viewFile;
            }
        }

        // 우선순위2
        // 컨트롤러에서 설정한 값이 있는 경우
        if($this->viewFileLayout) {
            $viewFile = $this->viewFileLayout;
            if($result = $this->isExistView($viewFile)) {
                return $result;
            }

            // 테마 파일
            if($result = $this->inThemeView($viewFile)) {
                return $result;
            }

            if(View::exists($viewFile)) {
                return $viewFile;
            }
        }



    }

    private function inThemeView($viewFile)
    {
        // 함수 중복 수행을 방지하기 위하여
        if(!$this->theme) {
            $theme = trim(xTheme()->getName(),'"');
            $theme = str_replace('/','.',$theme);
            $this->theme = $theme;
        }

        $theme = $this->theme;
        if($theme) {
            // 테마 리소스가 있는 경우
            if (View::exists("theme::".$theme.".".$viewFile)) {
                return "theme::".$theme.".".$viewFile;
            }
        }
    }

    private function isExistView($viewFile)
    {

        // 패키지 경로가 포함됨
        // 페키지 경로를 모두 포함해서 검사함
        if (strpos($viewFile, '::') !== false) {
            if (View::exists($viewFile)) {
                return $viewFile;
            }
        }

        if($viewFile = $this->inSlotView($viewFile)) {
            return $viewFile;
        }

        return false;
    }

    // 슬롯안에 뷰가 있는지 검사
    private function inSlotView($viewFile)
    {
        $prefix = "www";

        if(!$this->slot) {
            // www_slot() 함수 중복 수행을 방지하기 위하여
            // 프로퍼티에 값 임시 저장
            $this->slot = $slot = www_slot();
        }

        $slot = $this->slot;

        // 페키지 경로가 없는 겨우에는 slot에서 검색
        // 먼저 슬롯 안에 있는지 검사
        if($slot) {
            if(View::exists($prefix."::".$slot.".".$viewFile)) {
                return $prefix."::".$slot.".".$viewFile;
            }
        }
        // slot에 없는 경우 상위 www 공용안에 있는지 검사
        else {
            if(View::exists($prefix."::".$viewFile)) {
                return $prefix."::".$viewFile;
            }
        }

        return false;
    }


    /**
     * 컨트롤러에 테마를 설정합니다.
     */
    protected function setTheme($name)
    {
        $this->actions['theme'] = $name;

        // 세션에 테마 저장
        session()->put('theme', $name);

        return $this;
    }

    // Request에서 전달된 query 스트링값을 저장합니다.
    protected function checkRequestQuery($request)
    {
        if($request->query) {
            foreach($request->query as $key => $q) {
                $this->actions['request']['query'][$key] = $q;
                $this->actions['query'][$key] = $q;

                // 필터검색 요건확인
                $len = strlen("filter_");
                if(strlen($key) > $len) {
                    if(substr($key,0,$len) == "filter_") {
                        $_key = substr($key,$len);
                        $this->actions['filter'][$_key] = $q;
                    }
                }
            }
        }
        //dd($this->actions);
        return $this;
    }

    protected function checkRequestNesteds($request)
    {
        //dd($this->actions['nesteds']);
        //dd($request->id);
        //dd($request);

        if (isset($this->actions['nesteds'])) {
            foreach($this->actions['nesteds'] as $i => $nested) {
                if(isset($request->$nested)) {
                    unset($this->actions['nesteds'][$i]);
                    $this->actions['nesteds'][$nested] = $request->$nested;
                    $this->actions['request'][$nested] = $request->$nested;
                }
            }
        }

        return $this;
    }



    public function show(Request $request, $id)
    {
        $this->checkRequestNesteds($request);
        $this->checkRequestQuery($request);

        // 메뉴 설정
        $user = Auth::user();
        $this->setUserMenu($user);

        // 권한
        $this->permitCheck();
        if($this->permit['read']) {

        }

        // 권한 접속 실패
        return view("jiny-wire-table::error.permit",[
            'actions'=>$this->actions,
            'request'=>$request
        ]);
    }

    public function create(Request $request)
    {
        $this->checkRequestNesteds($request);
        $this->checkRequestQuery($request);

        // 메뉴 설정
        $user = Auth::user();
        $this->setUserMenu($user);

        // 권한
        $this->permitCheck();
        if($this->permit['create']) {

            // 메인뷰 페이지...
            if (isset($this->actions['view']['edit'])) {
                $view = $this->actions['view']['edit'];
            } else {
                $view = "jiny-wire-table::edit";
            }

            return view($view,[
                'actions'=>$this->actions
            ]);
        }

        // 권한 접속 실패
        return view("jiny-wire-table::error.permit",[
            'actions'=>$this->actions,
            'request'=>$request
        ]);
    }

    public function store(Request $request)
    {
        $this->checkRequestNesteds($request);
        $this->checkRequestQuery($request);

        // 권한
        $this->permitCheck();
        if($this->permit['create']) {


        }

        // 권한 접속 실패
        return view("jiny-wire-table::error.permit",[
            'actions'=>$this->actions,
            'request'=>$request
        ]);

    }

    // public function edit(Request $request, $id)
    // {
    //     $this->checkRequestNesteds($request);
    //     $this->checkRequestQuery($request);

    //     // 메뉴 설정
    //     $user = Auth::user();
    //     $this->setUserMenu($user);

    //     // 권한
    //     $this->permitCheck();
    //     if($this->permit['update']) {
    //         // 마지막 값이, id로 간주합니다.
    //         $keyId = array_key_last($this->actions['nesteds']);
    //         $this->actions['id'] = $this->actions['nesteds'][$keyId];

    //         return view("jjiny-wire-table::edit",['actions'=>$this->actions]);
    //     }

    //     // 권한 접속 실패
    //     return view("jiny-wire-table::error.permit",[
    //         'actions'=>$this->actions,
    //         'request'=>$request
    //     ]);
    // }



    // public function update(Request $request, $id)
    // {
    //     $this->checkRequestNesteds($request);
    //     $this->checkRequestQuery($request);

    //     // 권한
    //     $this->permitCheck();
    //     if($this->permit['update']) {


    //     }

    //     // 권한 접속 실패
    //     return view("jiny-wire-table::error.permit",[
    //         'actions'=>$this->actions,
    //         'request'=>$request
    //     ]);
    // }

    // public function destroy($id, Request $request)
    // {
    //     $this->checkRequestNesteds($request);
    //     $this->checkRequestQuery($request);

    //     // 권한
    //     $this->permitCheck();
    //     if($this->permit['delete']) {
    //         // 마지막 값이, id로 간주합니다.
    //         $keyId = array_key_last($this->actions['nesteds']);
    //         $this->actions['id'] = $this->actions['nesteds'][$keyId];
    //     }

    //     // 권한 접속 실패
    //     return view("jiny-wire-table::error.permit",[
    //         'actions'=>$this->actions,
    //         'request'=>$request
    //     ]);
    // }

    /**
     * delete 선택한 항목 삭제
     *
     * @param  mixed $request
     * @return void
     */
    public function delete(Request $request)
    {
        $this->checkRequestNesteds($request);
        $this->checkRequestQuery($request);

        // 권한
        $this->permitCheck();
        if($this->permit['delete']) {

            $ids = $request->ids;
            // 선택한 항목 삭제 AJAX
            DB::table($this->tablename)->whereIn('id', $ids)->delete();
            return response()->json(['status'=>"200", 'ids'=>$ids]);

        }

        // 권한 접속 실패
        return response()->json(['status'=>"201",'message'=>"권한 설정없음"]);
    }

}
