<?php

declare(strict_types=1);

namespace Photobooth\Operator\Tests;

use PHPUnit\Framework\TestCase;
use Photobooth\Operator\Container;
use Photobooth\Operator\Storage\Database;
use Photobooth\Operator\Support\Uuid;

final class LegacyMigrationTest extends TestCase
{
    public function testLegacyDatabaseKeepsPhotosFinalPrintAndShareAcrossMigration(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/pb-migration-' . Uuid::v4();
        mkdir($directory, 0750, true);
        $env = ['DATA_DIR'=>$directory, 'DATABASE_PATH'=>$directory . '/db.sqlite', 'SHARE_BASE_URL'=>'http://192.168.4.1:8081', 'SHARE_SIGNING_KEY'=>'migration-test-only-signing-key-0123456789'];
        $db = new Database($env['DATABASE_PATH']);
        $pdo = $db->pdo();
        $sql = file_get_contents($root . '/migrations/001_initial_schema.sql');
        $pdo->exec($sql);
        $pdo->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL)');
        $pdo->prepare('INSERT INTO schema_migrations VALUES (?,?,?)')->execute(['001_initial_schema.sql', hash('sha256', $sql), gmdate('c')]);
        $user = Uuid::v4(); $session = Uuid::v4(); $job = Uuid::v4(); $render = Uuid::v4(); $final = Uuid::v4(); $print = Uuid::v4(); $share = Uuid::v4();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $pdo->prepare('INSERT INTO users VALUES (?,?,?,?,NULL,?,?)')->execute([$user,'legacy',password_hash('LegacyPass123!',PASSWORD_DEFAULT),'operator',$now,$now]);
        $pdo->prepare("INSERT INTO sessions (id,state,revision,selected_lut,template_id,current_render_id,current_final_id,created_by,created_at,updated_at) VALUES (?,'FINALIZED',9,'original','vertical-three-v1',?,?,?,?,?)")
            ->execute([$session,$render,$final,$user,$now,$now]);
        $photos = [];
        foreach ([1,2,3] as $slot) {
            $id = Uuid::v4(); $photos[] = $id;
            $path = "sessions/{$session}/originals/slot-{$slot}/v1.jpg";
            mkdir(dirname($directory . '/' . $path),0750,true);
            copy($root . "/tests/fixtures/slot-{$slot}-v1.jpg", $directory . '/' . $path);
            $hash = hash_file('sha256',$directory . '/' . $path);
            $pdo->prepare('INSERT INTO photos VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$session,$slot,1,$path,$hash,1200,600,filesize($directory . '/' . $path),1,$now]);
            $pdo->prepare('INSERT INTO session_slots VALUES (?,?,?,?)')->execute([$session,$slot,$id,$now]);
        }
        $pdo->prepare("INSERT INTO jobs (id,session_id,type,status,accepted_revision,payload,created_at) VALUES (?,?,'render','SUCCEEDED',8,'{}',?)")->execute([$job,$session,$now]);
        $path = "sessions/{$session}/finalized/{$final}.jpg";
        mkdir(dirname($directory . '/' . $path),0750,true);
        $image = imagecreatetruecolor(1181,1748);
        imagejpeg($image,$directory . '/' . $path,95);
        imagedestroy($image);
        $hash = hash_file('sha256',$directory . '/' . $path);
        $pdo->prepare("INSERT INTO renders (id,session_id,job_id,lut_id,lut_version,template_id,template_version,pipeline_version,source_photo_ids,input_signature,image_path,preview_path,sha256,preview_sha256,width,height,created_at) VALUES (?,?,?,'original','1.0.0','vertical-three-v1','1.0.0','1.0.0',?,'legacy',?,?,?,?,1181,1748,?)")
            ->execute([$render,$session,$job,json_encode($photos),$path,$path,$hash,$hash,$now]);
        $pdo->prepare('INSERT INTO final_assets VALUES (?,?,?,?,?,?,?,?,?)')->execute([$final,$session,$render,$path,$hash,1181,1748,filesize($directory . '/' . $path),$now]);
        $pdo->prepare("INSERT INTO print_requests (id,session_id,final_asset_id,copies,action,status,created_by,created_at,updated_at) VALUES (?,?,?,1,'print','COMPLETED',?,?,?)")->execute([$print,$session,$final,$user,$now,$now]);
        $token = $share . '.' . hash_hmac('sha256',$share . ':' . $final,$env['SHARE_SIGNING_KEY']);
        $pdo->prepare('INSERT INTO share_tokens (id,session_id,final_asset_id,token_hash,created_by,created_at,expires_at) VALUES (?,?,?,?,?,?,?)')->execute([$share,$session,$final,hash('sha256',$token),$user,$now,gmdate('Y-m-d\TH:i:s\Z',time()+3600)]);
        $container = null;
        try {
            $container = new Container($env);
            self::assertSame([], $container->db->pdo()->query('PRAGMA foreign_key_check')->fetchAll());
            $restored = $container->sessionService->getSession($session);
            self::assertSame($session,$restored['id']);
            self::assertSame(9,$restored['revision']);
            self::assertTrue($restored['configuration_locked']);
            self::assertSame('postcard-three',$restored['layout_id']);
            self::assertSame('1.0.0',$restored['lut_version']);
            self::assertSame(100,$restored['lut_intensity']);
            self::assertSame('identity-original',$restored['lut_sha256']);
            self::assertSame($photos,array_column(array_column($restored['slots'],'photo'),'id'));
            self::assertSame($hash,$restored['current_final']['sha256']);
            self::assertSame('COMPLETED',$restored['print_summary']['requests'][0]['status']);
            self::assertSame($hash,$container->shareService->resolveValidToken($token)['sha256']);
            self::assertSame($hash,hash_file('sha256',$directory . '/' . $path));
        } finally {
            if ($container !== null) $container->assetStore->removeDirectoryRecursive($directory);
        }
    }
}
