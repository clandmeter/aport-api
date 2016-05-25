<?php
/**
 * @category  PHP
 * @author    V.Krishn <vkrishn4@gmail.com>
 * @copyright Copyright (c) 2016 V.Krishn <vkrishn4@gmail.com>
 * @license   GPL
 * @link      http://github.com/insteps/aport-api
 * @version   0.0.1
 *
 */

use Phalcon\Loader;
use Phalcon\Mvc\Url;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Mysql as PdoMysql;
use Phalcon\Db\Adapter\Pdo\Sqlite as PdoSqlite;
$url = new Url();

// Application configuration data
// -------------------------------
$config['version'] = '0.0.1';

# Setting a full domain as base URI
# cPhalcon use '_url=' to pass request, 
#  eg. http://localhost/aport-api/?_url=
# use this if .htaccess or url rewrite is unavailable.
$url->setBaseUri('http://localhost/aport-api');

$config['apiurl'] = $url->getBaseUri();

// for mysql
$config['mysql'] = array(
    "host" =>     "localhost",
    "username" => "root",
    "password" => "",
    "dbname" =>   "aports"
);
$config['mysql']["persistent"] = false;

// for sqlite
$config['sqlite'] = array(
    "dbname" => "db/aports.db"
);

$config['dbtype'] = 'sqlite';

$config['app']['pglimit'] = 50; #default items per page
// -------------------------------

// Use Loader() to autoload our model
$loader = new \Phalcon\Loader();

$loader->registerDirs(
    array(
        __DIR__ . '/models/'
    )
)->register();

$di = new FactoryDefault();

if( $config['dbtype'] === 'mysql' ) {
    // Set up the database service (mysql)
    $di->set('dbMysql', function () use ($config){
        return new PdoMysql($config['mysql']);
    });
} else {
    // Set up the database service (sqlite)
    $di->set('dbSqlite', function () use ($config){
        return new PdoSqlite($config['sqlite']);
    });
}

//$app = new Micro();
$app = new \Phalcon\Mvc\Micro($di);
$app->config = $config;
$app->myapi = new stdClass;
$app->myapi->pglimit = $config['app']['pglimit'];
$app->myapi->_reqUrl = $app->request->get('_url'); #set default _reqUrl

// Define routes here
// ====================

/*

The Site related API consists of the following methods:
  Method  | URL                                    | Action
  ------------------------------------------------------------------------------------------------
  GET     | /$                                     | Welcome message
  GET     | /about$                                | About sites api service
  GET     | /docs$                                 | Documents welcome page
  GET     | /docs/(.*)$                            | Documents subpages

*/

$app->get('/', function () {
    echo "<h1>Welcome !! </h1>The Json API for Alpine Linux aports.\n";
});

$app->get('/about', function () use ($app, $config) {
    $data = initJapiData($app, 'about');
    $data->meta = array(
        'version' => $config['version'],
        'backend' => '',
        'apiurl' => $config['apiurl']
    );
    $data->data[] = (object)array();
    json_api_encode($data, $app);
});

$app->get('/docs', function () {
    echo "<h1>Api Documents</h1>Todo.\n";
});

/*

The Aports API consists of the following methods: # TODO - clean text import from lua

  Method  | URL                                    | Action
  ------------------------------------------------------------------------------------------------
  GET     | /$                                     | web.RedirectHandler, {"/packages"}
  GET     | /packages/(.*)/relationships/contents$ | ApiRelationshipsContentRenderer, {aports=aports,model=model}
  GET     | /packages/(.*)/relationships/(.*)$     | ApiPackagesRelationship, {aports=aports,model=model}
  GET     | /packages/(.*)$                        | ApiPackageRenderer {aports=aports,model=model}
  GET     | /packages                              | ApiPackagesRenderer {aports=aports,model=model}
  GET     | /packages/page/<num>                   | Paginated object for packages
  --contents --------------
  GET     | /contents/(.*)/relationships/packages$ | ApiRelationshipsPackagesRenderer, {aports=aports,model=model}
  GET     | /contents/(.*)$                        | ApiContentRenderer {aports=aports,model=model}
  GET     | /contents                              | ApiContentsRenderer {aports=aports,model=model}
  --static ----------------
  GET     | favicon.ico                            | web.StaticFileHandler, "assets/favicon.ico"
  --others ----------------
  DELETE  | /api/test/100                          | 
  POST    | /api/add/10                            | 
  PUT     | /api/add/10                            | 

*/

// Retrieves packages
$app->get('/packages', function () use ($app) {

    $data = initJapiData($app, 'packages');

    # get Packages count and figure out paginations
    $first = "1"; $next = "2";
    $limit = $app->myapi->pglimit;
    $res = Packages::find();
    $tnum = count($res);
    $tpgs = floor($tnum/$limit);
    $mod = $tnum%$limit;
    if($mod > 0) $tpgs = $tpgs+1;

    $offset = isset($app->myapi->offset) ? $app->myapi->offset : 0;

    $data->meta = array(
        'total-pages' => $tpgs,
        'per-page' => $app->myapi->pglimit,
        'count' => $tnum
    );

    $_reqUrl = cleanUri($app->request->get('_url'));
    //$app->myapi->_reqUrl = $_reqUrl;

    if( isset($app->myapi->offset) ) {
      $slink = preg_replace('#\/page.*$#', '', $_reqUrl);
      $next = $app->myapi->pgNext;
      $app->myapi->_reqUrl = $slink;
    } else {
      $slink = $_reqUrl;
    }
    $slink = $app->config['apiurl'] . $slink;

    $data->links = (object)array();
    $data->links->self = $app->config['apiurl'] . $_reqUrl;
    $data->links->next = $slink.'/page/'.$next;
    $data->links->last = $slink.'/page/'.$tpgs;
    $data->links->first = $slink.'/page/'.$first;

    $res = Packages::find(
        array(
            "order" => "id DESC",
            "limit" => $limit,
            "offset" => "$offset"
        )
    );
    $data->data = fmtData($res, 'packages.', $app)->data;
    $data = populate_maintainer($data, $app);

    if($data) json_api_encode($data, $app);

});

// Retrieves packages by paginations (defaults)
$app->get('/packages/page', function () use ($app) {
    $app->myapi->offset = 0;
    $app->myapi->pgNext = 2;
    $app->myapi->pgPrev = 1;
    $app->handle("/packages");
});

// Retrieves packages by paginations
$app->get('/packages/page/{page:[0-9]+}', function ($page) use ($app) {
    $page = (int)$page;

    $limit = $app->myapi->pglimit;
    $res = Packages::find();
    $tnum = count($res);
    $tpgs = floor($tnum/$limit);
    $mod = $tnum%$limit;
    if($mod > 0) $tpgs = $tpgs+1;
    if($page > $tpgs) return $app->handle('/404');

    $multiplier = ($page <= 1) ? 0 : $page-1;
    $app->myapi->offset = $multiplier * $app->myapi->pglimit;
    $app->myapi->pgNext = ($page+1 > $tpgs) ? $page : $page+1;
    $app->myapi->pgPrev = ($page-1 <= 0) ? 1 : $page-1;
    $app->handle("/packages");
});

$app->get('/packages/name/{name:[a-z0-9\-\_\.]+}', function ($name) use ($app) {
    $data = initJapiData($app, 'packages');

    $res = Packages::find( array( "name = '$name'", "order" => "id DESC") );
    $tnum = count($res);
    if($tnum < 1) { $app->handle('/404'); return; }

    $data->meta = array(
        'count' => $tnum
    );
    $data->data = fmtData($res, 'packages.', $app)->data;
    $data = populate_maintainer($data, $app);
    if($data) json_api_encode($data, $app);
});

// Retrieves packages by name
$app->get('/packages/{name:[a-z0-9\-\_\.]+}', function ($name) use ($app) {
    return $app->handle("/packages/name/$name");
});

// Retrieves packages by id
// would override /packages/{name} if name is all digits
$app->get('/packages/{pid:[0-9]+}', function ($pid) use ($app) {
    return $app->handle("/packages/pid/$pid");
});

// Retrieves packages by relationships
$app->get('/packages/{id:[0-9]+}/relationships/{type}', function ($id, $type) use ($app) {

    $subtype = 'pid';

    if($type === 'flagged') {
        $res = Packages::findFirst( array( "id = '$id'", 'limit' => 1) );
        $id = $res->fid;
    }

    return $app->handle("/$type/$subtype/$id");
    //$app->handle('/404');

});

// Retrieves packages by id
$app->get('/packages/pid/{pid:[0-9]+}', function ($pid) use ($app) {
    $data = initJapiData($app, 'packages');

    $res = Packages::find( array( "id = '$pid'", 'limit' => 1) );
    $data->data = fmtData($res, 'packages.id', $app)->data;
    $data = populate_maintainer($data, $app);
    if($data) json_api_encode($data, $app);
});

$app->get('/packages/fid/{fid:[0-9]+}', function ($fid) use ($app) {
    $data = initJapiData($app, 'packages');

    $res = Packages::find( array( "fid = '$fid'", "order" => "id DESC") );
    $tnum = count($res);
    if($tnum < 1) { $app->handle('/404'); return; }

    $data->meta = array(
        'count' => $tnum
    );
    $data->data = fmtData($res, 'packages.', $app)->data;
    $data = populate_maintainer($data, $app);
    if($data) json_api_encode($data, $app);
});

$app->get('/origins/pid/{pid:[0-9]+}', function ($pid) use ($app) {
    return $app->handle("/packages/pid/$pid");
});

$app->get('/flagged/fid/{fid:[0-9]+}', function ($fid) use ($app) {
    return $app->handle("/flagged/pid/$fid");
});

$app->get('/flagged/{fid:[0-9]+}/relationships/{type}', function ($fid, $type) use ($app) {
    if($type === 'packages') {
        return $app->handle("/packages/fid/$fid");
    }
    $app->handle('/404');
});


$app->get('/{rel:install_if|provides|depends|contents|flagged}/pid/{pid:[0-9]+}',
    function ($rel, $pid) use ($app) {
    $rels['install_if'] = array("install_if", 'Installif', 'install_if.pid'); # name, className, fmtName
    $rels['provides'] = array("provides", 'Provides', 'provides.pid');
    $rels['depends'] = array("depends", 'Depends', 'depends.pid');
    $rels['contents'] = array("contents", 'Files', 'contents.pid');
    $rels['flagged'] = array("flagged", 'Flagged', 'flagged.fid');
    $_r = $rels[$rel];
    list($type, $subtype) = explode('.', $_r[2]);

    $data = initJapiData($app, $_r[0]);
    # meta
    $res = $_r[1]::find();
    $tnum = count($res);

    $data->meta = array(
        'total-files' => $tnum
    );
    # data
    $res = $_r[1]::find( array( "$subtype = '$pid'") );
    $tnum2 = count($res);
    if( ! $tnum2 > 0) return $app->handle('/404');
    $data->meta['pkg-count'] = $tnum2;
    $data->data = fmtData($res, $_r[2], $app)->data;
    return json_api_encode($data, $app);
});

// Retrieves contents(files) by id
$app->get('/contents/id/{id:[0-9]+}', function ($id) use ($app) {
    $data = initJapiData($app, 'contents');
    $res = Files::find( array( "id = '$id'", 'limit' => 1) );
    if( ! count($res) > 0) return $app->handle('/404');
    $data->data = fmtData($res, 'contents.id', $app)->data;
    return json_api_encode($data, $app);
});

// Retrieves package data by its content(files->id) relationships
$app->get('/contents/{id:[0-9]+}/relationships/{type}', function ($id, $type) use ($app) {
    if($type === 'packages') {
        $res = Files::find( array( "id = '$id'", 'limit' => 1) );
        $pid = $res[0]->pid;
        return $app->handle("/packages/$pid");
    }
    $app->handle('/404');
});

// Retrieves package data by its depends(name) relationships (funny relationships)
//  possibly taken as packages that depends on this given named pkg # TODO
$app->get('/depends/{name:[a-z]+.*}/relationships/{type}', function ($name, $type) use ($app) {
    $data = initJapiData($app, 'depends');

    if($type === 'packages') {
        $res = Depends::find( array( "name = '$name'") );
        if( ! count($res) > 0) return $app->handle('/404');
        $pid = $res[0]->pid;

        # ---------------------
        foreach($res as $d) {
            $a[] = $d->pid;
        }
        $l = trim(implode(',', array_unique($a)), ',');
        $l = preg_replace('#\,{2}+#', ',', $l);
        $phql = "SELECT * from Packages where id in ($l) ";
        $res = $app->modelsManager->executeQuery($phql);
        $tnum = count($res);
        $data->meta = array(
            'files' => $tnum
        );

        $data->data = fmtData($res, 'packages.', $app)->data;
        $data = populate_maintainer($data, $app);

        return json_api_encode($data, $app);
        # ---------------------

        //return $app->handle("/packages/$pid"); # TODO
    }
    $app->handle('/404');
});


# Error Responses
# --------------------------

# Error Responses 404
$app->notFound(function () use ($app) {
    $app->response->setStatusCode(404, "Not Found")->sendHeaders();
    $data = initJapiErrData($app, 
      array( '404', 'Not Acceptable', 'This is crazy, but this page was not found!' ));
    json_api_encode($data, $app);
});


# Error Responses 406
$app->get('/406', function () use ($app) {
    $app->response->setStatusCode(406, "Not Acceptable")->sendHeaders();
    $data = initJapiErrData($app, array( '406', 'Not Acceptable', '' ));
    json_api_encode($data, $app);
});

# Error Responses 415
$app->get('/415', function () use ($app) {
    $app->response->setStatusCode(415, "Unsupported Media Type")->sendHeaders();
    $data = initJapiErrData($app, array( '415', 'Unsupported Media Type', '' ));
    json_api_encode($data, $app);
});

# --------------------------

# for testing
$app->get('/say/welcome/{name}', function ($name) {
    echo "<h1>Welcome $name!</h1>";
});


/*
 Utility functions
 -----------------
*/

function cleanUri($_reqUrl) {
    //$_reqUrl = $app->request->get('_url');
    $pat = array('#\/{2}+#', '#\/{1}+$#');
    $rep = array('/', '');
    return preg_replace($pat, $rep, $_reqUrl);
}

function fmtMaintainer($d) {
    return $d['name'].' <'.$d['email'].'>';
}

function populate_maintainer($data, $app) { # move to model # TODO
    // add maintainer into object data
    //  using array method, rather than table join as in sql query
    foreach($data->data as $d) {
        $a[] = $d->attributes->maintainer;
    }
    $l = trim(implode(',', array_unique($a)), ',');
    $l = preg_replace('#\,{2}+#', ',', $l);
    if(empty($l)) return $data;
    $phql = "SELECT * from Maintainer where id in ($l) ";
    $res2 = $app->modelsManager->executeQuery($phql);
    if( ! count($res2) > 0 ) return $data;
    $m = array();
    foreach($res2 as $m1) {
        $m[$m1->id]['name'] = $m1->name;
        $m[$m1->id]['email'] = $m1->email;
    }
    foreach($data->data as $d) {
        $n = (int)$d->attributes->maintainer;
        if( $n >= 1 ) {
            $d->attributes->maintainer = fmtMaintainer($m[$n]);
        }
    }
    return $data;
}

function initJapiData($app, $type='') {
    $data = (object)array();
    # misc top level json api objects (non standards)
    $data->jsonapi = array('version' => '1.0');
    $data->meta = (object)array();

    $_reqUrl = cleanUri($app->request->get('_url'));
    $data->links = (object)array();
    $data->links->self = $app->config['apiurl'] . $_reqUrl;
    return $data;
}

function initJapiErrData($app, $type=array()) {
    //$slink = $app->config['apiurl'].$app->request->get('_url');
    $data = (object)array();
    $data->jsonapi = array('version' => '1.0');
    $data->error[] = array(
        'status' => @$type[0],
        'source' => (object)array(
                        'pointer' => $app->request->get('_url'),
                        //"parameter" => "include",
                    ),
        'title' => @$type[1],
        'detail' => @$type[2]
    );
    return $data;
}

# TO CLEAN ( repeating codes ), use model if better
function fmtData($res, $type, $app) {
    if ( ! $res ) { $app->handle('/404'); }

    list($type, $subtype) = explode('.', $type);
    $jsonApi = (object)array();

    if($type === 'flagged') {
        $dindentifier = 'fid';
        $list = array( "created", "reporter", "new_version", "message" );
        $relationships = array("packages");
    }

    if($type === 'install_if') {
        $dindentifier = 'pid';
        $list = array( "name", "version", "operator" );  # hard code list just to remove pid field # TODO
        $relationships = array("packages");
    }

    if($type === 'provides') {
        $dindentifier = 'pid';
        $list = array( "name", "version", "operator" );
        $relationships = array("packages");
        //$slink = '/' . 'files' . '/'; # TODO
    }

    if($type === 'depends') {
        $dindentifier = 'pid';
        $list = array( "name", "version", "operator" );
        $relationships = array("packages");
    }

    if($type === 'contents') { # from table 'files'
        $dindentifier = 'id';
        $list = array( "file", "path" );
        $relationships = array("packages");
    }

    if($type === 'packages') {
        $dindentifier = 'id';
        $list = array( # hard code list just to remove id field # TODO
             "license", "arch", "build_time", "maintainer", "checksum",
             "version", "installed_size", "branch", "size", "commit",
             "origin", "url", "repo", "name", "description", "fid"
        );
        $relationships = array( "depends", "provides", "install_if",
                                 "origins", "contents", "flagged" );
    }

    $slink = '/' . $type . '/';

    foreach ($res as $item) {
        $obj = (object)array();
        $obj->id = $item->$dindentifier;
        $obj->type = $type;
        $obj->links = new stdClass;

        foreach($list as $l) {
            $newitem[$l] = $item->$l;
        }
        $obj->attributes = (object)$newitem;
        if( $subtype === 'pid' ) {
        } else {
        }

        # see http://jsonapi.org/format/#document-top-level if still an issue
        //$jsonApi->data = $obj; # primary data in a single resource identifier object
        $jsonApi->data[] = $obj; # for more than one object (array)

        # using pid would add name in url, either add id columns to tables
        #  or deal with weird file names

        if($type == 'contents' || $type === 'packages') {
            $obj->links->self = $slink.$item->id;
            $rlink = $slink.$item->id .'/relationships/';

        }
        if($type === 'install_if' || $type === 'provides' || $type === 'depends') {
            $obj->links->self = $slink.$item->name;
            $rlink = $slink.$item->name .'/relationships/';
        }
        if($type === 'flagged') {
            $obj->links->self = $slink.$item->fid;
            $rlink = $slink.$item->fid .'/relationships/';
        }
        // some cleaning
        $obj->links->self = $app->config['apiurl'].preg_replace('#\/{2}+#', '/', $obj->links->self);
        $rlink = $app->config['apiurl'].preg_replace('#\/{2}+#', '/', $rlink);

        # make relationships objects links
        foreach($relationships as $val) {
            $rels[$val]['links']['self'] = $rlink.$val;
        }
        $obj->relationships = (object)$rels;
    }
    return $jsonApi;

    $app->handle('/404');
}

function json_api_encode($data, $app, $flags=array()) {
    $header['japi'] = 'application/vnd.api+json';
    //$response = new Phalcon\Http\Response();

    //enable in production
    $app->response->setContentType($header['japi'])->sendHeaders();
    echo json_encode($data);
}

# removes php version numbers, 
# considered as probable security issue
header_remove('X-Powered-By');

$app->handle();
