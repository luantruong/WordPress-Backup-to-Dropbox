<?php
/**
 * @copyright Copyright (C) 2011 Michael De Wildt. All rights reserved.
 * @author Michael De Wildt (http://www.mikeyd.com.au/)
 * @license This program is free software; you can redistribute it and/or modify
 *          it under the terms of the GNU General Public License as published by
 *          the Free Software Foundation; either version 2 of the License, or
 *          (at your option) any later version.
 *
 *          This program is distributed in the hope that it will be useful,
 *          but WITHOUT ANY WARRANTY; without even the implied warranty of
 *          MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *          GNU General Public License for more details.
 *
 *          You should have received a copy of the GNU General Public License
 *          along with this program; if not, write to the Free Software
 *          Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110, USA.
 */
require_once 'mock-wp-functions.php';

class WP_Backup_Output_Test extends PHPUnit_Framework_TestCase {

	private $out;
	private $config;
	private $dropbox;

	public function setUp() {
		reset_globals();
		$this->dropbox = Mockery::mock('Dropbox_Facade');
		$this->config = WP_Backup_Config::construct();
		$this->config->set_option('dropbox_location', 'DropboxLocation');
		$this->out = new WP_Backup_Output($this->dropbox, $this->config);

		$fh = fopen(__DIR__ . '/Out/file.txt', 'w');
		fwrite($fh, "file.txt");
		fclose($fh);
	}

	public function tearDown() {
		unlink(__DIR__ . '/Out/file.txt');
		$this->config->clean_up();
		Mockery::close();
	}

	public function testOutFileNotInDropbox() {
		$this->config->set_option('last_backup_time', time());
		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->with('DropboxLocation/Out')
			->andReturn(array())
			->once()

			->shouldReceive('upload_file')
			->with('DropboxLocation/Out/file.txt', __DIR__ . '/Out/file.txt')
			->once()
			;

		$this->out->out(__DIR__, __DIR__ . '/Out/file.txt');
	}

	public function testOutFileInDropboxButOlder() {
		$this->config->set_option('last_backup_time', filemtime(__DIR__ . '/Out/file.txt') - 1);

		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->with('DropboxLocation/Out')
			->andReturn(array('file.txt'))
			->once()

			->shouldReceive('upload_file')
			->with('DropboxLocation/Out/file.txt', __DIR__ . '/Out/file.txt')
			->once()
			;

		$this->out->out(__DIR__, __DIR__ . '/Out/file.txt');
	}

	public function testOutFileInDropboxAndNotUpdated() {
		$this->config->set_option('last_backup_time', filemtime(__DIR__ . '/Out/file.txt') + 1);

		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->with('DropboxLocation/Out')
			->andReturn(array('file.txt'))
			->once()

			->shouldReceive('upload_file')
			->never()
			;

		$this->out = new WP_Backup_Output($this->dropbox, $this->config);

		$this->out->out(__DIR__, __DIR__ . '/Out/file.txt');

		$uploaded = $this->config->get_processed_files();
		$this->assertEmpty($uploaded);
	}

	public function testOutFileUploadWarning() {
		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->with('DropboxLocation/Out')
			->once()

			->andReturn(array())
			->shouldReceive('upload_file')
			->andThrow(new Exception('Error'))
			->once()
			;

		$this->out->out(__DIR__, __DIR__ . '/Out/file.txt');

		$history = $this->config->get_history();
		$this->assertEquals(
			"Could not upload '/Users/mikey/Documents/wpb2d/git/WordPress-Backup-to-Dropbox/Tests/Out/file.txt' due to the following error: Error",
			$history[0][2]
		);
	}

	public function testOutFileUploadFileTooLarge() {
		if (!file_exists(__DIR__ . '/Out/bigFile.txt')) {
			$fh = fopen(__DIR__ . '/Out/bigFile.txt', 'w');
			for ($i = 0; $i < 3461120; $i++)
				fwrite($fh, "a");
			fclose($fh);
		}

		ini_set('memory_limit', '8M');
		$this->out = new WP_Backup_Output($this->dropbox, $this->config);

		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->never()

			->shouldReceive('upload_file')
			->never()
			;

		$this->out->out(__DIR__, __DIR__ . '/Out/bigFile.txt');

		$history = $this->config->get_history();
		$this->assertNotEmpty($history);
		$this->assertEquals(
			"file 'bigFile.txt' exceeds 40 percent of your PHP memory limit. The limit must be increased to back up this file.",
			$history[0][2]
		);
		ini_restore('memory_limit');
	}

	public function testOutFileUploadUnauthorized() {

		$this
			->dropbox

			->shouldReceive('get_directory_contents')
			->with('DropboxLocation/Out')
			->andReturn(array())
			->once()

			->shouldReceive('upload_file')
			->andThrow(new Exception('Unauthorized'))
			->once()
			;

		try {
			$this->out->out(__DIR__, __DIR__ . '/Out/file.txt');
		} catch (Exception $e) {
			return;
		}

		$this->fail('An expected exception has not been raised.');
	}
}