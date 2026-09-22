<?php
declare(strict_types=1);
namespace Ecrm\Domain\Customers;
use Ecrm\Storage\AtomicJsonStore;use Ecrm\Support\UuidV7;use InvalidArgumentException;
final class CustomerService {public function __construct(private AtomicJsonStore $store){} public function create(array $input):array{$name=trim((string)($input['name']??''));if($name==='')throw new InvalidArgumentException('Customer name is required');$now=gmdate(DATE_ATOM);$r=['id'=>UuidV7::generate(),'number'=>$input['number']??null,'name'=>$name,'contact_person'=>trim((string)($input['contact_person']??'')),'mobile'=>trim((string)($input['mobile']??'')),'email'=>trim((string)($input['email']??'')),'gstin'=>strtoupper(trim((string)($input['gstin']??''))),'status'=>'active','tags'=>array_values($input['tags']??[]),'created_at'=>$now,'updated_at'=>$now];$this->store->put('customers',$r['id'],$r);return$r;} public function all():array{return $this->store->all('customers');}}
