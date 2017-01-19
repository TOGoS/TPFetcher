<?php

class TOGoS_FetcherTest extends PHPUnit_Framework_TestCase
{
	protected function makeFetcher() {
		return new TOGoS_Fetcher('.ccouch', 'test-cache', array_map(array('TOGoS_Fetcher','defuzzRemoteRepoPrefix'), array(
			'piccouch.appspot.com',
			'fs.marvin.nuke24.net',
			'toggh1.nuke24.net',
			'wherever-files.nuke24.net',
		)));
	}
	
	public function testCheckout() {
		$fetcher = $this->makeFetcher();
		$destFile = 'temp/TOGoS-Narg_9-splash.jpg';
		if( file_exists($destFile) ) unlink( $destFile );
		$fetcher->checkout( 'urn:bitprint:WYD6VDKXNMXCV2WGQZMHBAOCWLU2UMNR.VPQ4PXFHT7YKYMQCRTIUUFI3O2RNAE7BKB2TSIY', $destFile );
		$this->assertTrue( file_exists($destFile) );
		$this->assertEquals( 37527, filesize($destFile) );
	}
	
	public function testCache() {
		$fetcher = $this->makeFetcher();
		$destFile = ".ccouch/data/test-cache/WY/WYD6VDKXNMXCV2WGQZMHBAOCWLU2UMNR";
		if( file_exists($destFile) ) unlink($destFile);
		$fetcher->cache( 'urn:bitprint:WYD6VDKXNMXCV2WGQZMHBAOCWLU2UMNR.VPQ4PXFHT7YKYMQCRTIUUFI3O2RNAE7BKB2TSIY' );
		$this->assertEquals( 1, $fetcher->downloadCount );
		$this->assertTrue( file_exists($destFile) );
		$this->assertEquals( 37527, filesize($destFile) );
		
		$fetcher->cache( 'urn:bitprint:WYD6VDKXNMXCV2WGQZMHBAOCWLU2UMNR.VPQ4PXFHT7YKYMQCRTIUUFI3O2RNAE7BKB2TSIY' );
		$this->assertEquals( 1, $fetcher->downloadCount );
		$this->assertTrue( file_exists($destFile) );
		$this->assertEquals( 37527, filesize($destFile) );
		
		unlink($destFile);
		
		$fetcher->cache( 'urn:bitprint:WYD6VDKXNMXCV2WGQZMHBAOCWLU2UMNR.VPQ4PXFHT7YKYMQCRTIUUFI3O2RNAE7BKB2TSIY' );
		$this->assertEquals( 2, $fetcher->downloadCount );
		$this->assertTrue( file_exists($destFile) );
		$this->assertEquals( 37527, filesize($destFile) );
	}
}
