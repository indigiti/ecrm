<?php
declare(strict_types=1);
namespace Ecrm\Http;
final class Application {
 public static function run(): void {
  header('Content-Type: text/html; charset=utf-8');
  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>eCRM</title></head><body><main><h1>eCRM</h1><p>Foundation installed. CRM workspace is being initialized.</p></main></body></html>';
 }
}
