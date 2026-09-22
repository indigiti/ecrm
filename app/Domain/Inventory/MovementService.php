<?php
declare(strict_types=1);
namespace Ecrm\Domain\Inventory;
use Ecrm\Storage\AtomicJsonStore;use Ecrm\Support\UuidV7;use InvalidArgumentException;
final class MovementService {public function __construct(private AtomicJsonStore $store){} public function move(string $productId,?string $from,string $to,float $qty,string $type='transfer'):array{if($productId===''||$to===''||$qty<=0)throw new InvalidArgumentException('Invalid inventory movement');$r=['id'=>UuidV7::generate(),'product_id'=>$productId,'from_location_id'=>$from,'to_location_id'=>$to,'quantity'=>$qty,'type'=>$type,'created_at'=>gmdate(DATE_ATOM)];$this->store->put('inventory_movements',$r['id'],$r);return$r;}}
