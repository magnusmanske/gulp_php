#!/usr/bin/php
<?PHP

require_once ( '/data/project/gulp/scripts/gulp.php' ) ;

$gulp = new GULP ;

$result = $gulp->getOrCreateUserID ( '' ) ;
if ( $result ) die ("getOrCreateUserID: Blank user OK\n") ;

$result = $gulp->getOrCreateUserID ( 'Magnus_Manske' ) ;
if ( !isset($result) or $result!=1 ) die ("getOrCreateUserID: Not 1\n") ;

if ( $gulp->createNewList('',1) ) die ( 'createList: blank') ;
if ( $gulp->createNewList('something',0) ) die ( 'createList: 0 user') ;

if ( 0 ) {
	list ( $list_id , $revision_id ) = $gulp->createNewList ( 'Test' , $gulp->getOrCreateUserID ( 'Magnus_Manske' ) ) ;
	print "List {$list_id}, revision {$revision_id}\n" ;
}

if ( 1 ) {
	$revision_id = 1 ;
	$cols = [ ['The text','text'] , ['The item','item'] , ['The int','int'] , ['The page','page'] ] ;
	$gulp->setColumns ( $revision_id , $cols ) ;
}

?>