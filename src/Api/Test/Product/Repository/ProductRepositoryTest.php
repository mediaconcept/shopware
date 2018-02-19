<?php declare(strict_types=1);

namespace Shopware\Api\Test\Product\Repository;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Shopware\Api\Category\Repository\CategoryRepository;
use Shopware\Api\Entity\RepositoryInterface;
use Shopware\Api\Entity\Search\Criteria;
use Shopware\Api\Entity\Search\IdSearchResult;
use Shopware\Api\Entity\Search\Query\TermQuery;
use Shopware\Api\Entity\Search\Sorting\FieldSorting;
use Shopware\Api\Entity\Write\FieldException\WriteStackException;
use Shopware\Api\Product\Collection\ProductBasicCollection;
use Shopware\Api\Product\Event\Product\ProductBasicLoadedEvent;
use Shopware\Api\Product\Event\Product\ProductWrittenEvent;
use Shopware\Api\Product\Event\ProductManufacturer\ProductManufacturerBasicLoadedEvent;
use Shopware\Api\Product\Event\ProductManufacturer\ProductManufacturerWrittenEvent;
use Shopware\Api\Product\Repository\ProductManufacturerRepository;
use Shopware\Api\Product\Repository\ProductRepository;
use Shopware\Api\Product\Struct\ProductBasicStruct;
use Shopware\Api\Product\Struct\ProductDetailStruct;
use Shopware\Api\Product\Struct\ProductManufacturerBasicStruct;
use Shopware\Api\Tax\Definition\TaxDefinition;
use Shopware\Api\Tax\Event\Tax\TaxWrittenEvent;
use Shopware\Api\Tax\Struct\TaxBasicStruct;
use Shopware\Context\Struct\TranslationContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProductRepositoryTest extends KernelTestCase
{
    /**
     * @var RepositoryInterface
     */
    private $repository;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var TranslationContext
     */
    private $context;

    protected function setUp()
    {
        self::bootKernel();
        parent::setUp();
        $this->container = self::$kernel->getContainer();
        $this->repository = $this->container->get(ProductRepository::class);
        $this->eventDispatcher = $this->container->get('event_dispatcher');
        $this->connection = $this->container->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeUpdate('DELETE FROM product');
        $this->context = TranslationContext::createDefaultContext();
    }

    protected function tearDown()
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testWriteCategories()
    {
        $id = Uuid::uuid4();

        $data = [
            'id' => $id->toString(),
            'name' => 'test',
            'price' => 15,
            'manufacturer' => ['name' => 'test'],
            'tax' => ['name' => 'test', 'rate' => 15],
            'categories' => [
                ['id' => $id->toString(), 'name' => 'asd'],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $record = $this->connection->fetchAssoc('SELECT * FROM product_category WHERE product_id = :id', ['id' => $id->getBytes()]);
        $this->assertNotEmpty($record);
        $this->assertEquals($record['product_id'], $id->getBytes());
        $this->assertEquals($record['category_id'], $id->getBytes());

        $record = $this->connection->fetchAssoc('SELECT * FROM category WHERE id = :id', ['id' => $id->getBytes()]);
        $this->assertNotEmpty($record);
    }

    public function testWriteProductWithDifferentTaxFormat()
    {
        $tax = Uuid::uuid4()->toString();

        $data = [
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'tax' => ['rate' => 19, 'name' => 'without id'],
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'tax' => ['id' => $tax, 'rate' => 17, 'name' => 'with id'],
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'taxId' => $tax,
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'tax' => ['id' => $tax, 'rate' => 18],
            ],
        ];

        $this->repository->create($data, $this->context);
        $ids = array_column($data, 'id');
        $products = $this->repository->readBasic($ids, $this->context);

        $product = $products->get($ids[0]);

        /* @var ProductBasicStruct $product */
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(TaxBasicStruct::class, $product->getTax());
        $this->assertEquals('without id', $product->getTax()->getName());
        $this->assertEquals(19, $product->getTax()->getRate());

        $product = $products->get($ids[1]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(TaxBasicStruct::class, $product->getTax());
        $this->assertEquals($tax, $product->getTaxId());
        $this->assertEquals($tax, $product->getTax()->getId());
        $this->assertEquals('with id', $product->getTax()->getName());
        $this->assertEquals(18, $product->getTax()->getRate());

        $product = $products->get($ids[2]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(TaxBasicStruct::class, $product->getTax());
        $this->assertEquals($tax, $product->getTaxId());
        $this->assertEquals($tax, $product->getTax()->getId());
        $this->assertEquals('with id', $product->getTax()->getName());
        $this->assertEquals(18, $product->getTax()->getRate());

        $product = $products->get($ids[2]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(TaxBasicStruct::class, $product->getTax());
        $this->assertEquals($tax, $product->getTaxId());
        $this->assertEquals($tax, $product->getTax()->getId());
        $this->assertEquals('with id', $product->getTax()->getName());
        $this->assertEquals(18, $product->getTax()->getRate());
    }

    public function testWriteProductWithDifferentManufacturerStructures()
    {
        $manufacturerId = Uuid::uuid4()->toString();

        $data = [
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'tax' => ['rate' => 17, 'name' => 'test'],
                'manufacturer' => ['name' => 'without id'],
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'tax' => ['rate' => 17, 'name' => 'test'],
                'manufacturer' => ['id' => $manufacturerId, 'name' => 'with id'],
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'tax' => ['rate' => 17, 'name' => 'test'],
                'manufacturerId' => $manufacturerId,
            ],
            [
                'id' => Uuid::uuid4()->toString(),
                'name' => 'Test',
                'price' => 10,
                'tax' => ['rate' => 17, 'name' => 'test'],
                'manufacturer' => ['id' => $manufacturerId, 'link' => 'test'],
            ],
        ];

        $this->repository->create($data, $this->context);
        $ids = array_column($data, 'id');
        $products = $this->repository->readBasic($ids, $this->context);

        $product = $products->get($ids[0]);

        /* @var ProductBasicStruct $product */
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(ProductManufacturerBasicStruct::class, $product->getManufacturer());
        $this->assertEquals('without id', $product->getManufacturer()->getName());

        $product = $products->get($ids[1]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(ProductManufacturerBasicStruct::class, $product->getManufacturer());
        $this->assertEquals($manufacturerId, $product->getManufacturerId());
        $this->assertEquals($manufacturerId, $product->getManufacturer()->getId());
        $this->assertEquals('with id', $product->getManufacturer()->getName());

        $product = $products->get($ids[2]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(ProductManufacturerBasicStruct::class, $product->getManufacturer());
        $this->assertEquals($manufacturerId, $product->getManufacturerId());
        $this->assertEquals($manufacturerId, $product->getManufacturer()->getId());
        $this->assertEquals('with id', $product->getManufacturer()->getName());

        $product = $products->get($ids[2]);
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertInstanceOf(ProductManufacturerBasicStruct::class, $product->getManufacturer());
        $this->assertEquals($manufacturerId, $product->getManufacturerId());
        $this->assertEquals($manufacturerId, $product->getManufacturer()->getId());
        $this->assertEquals('with id', $product->getManufacturer()->getName());
        $this->assertEquals('test', $product->getManufacturer()->getLink());
    }

    public function testReadAndWriteOfProductManufacturerAssociation()
    {
        $id = Uuid::uuid4();

        //check nested events are triggered
        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects($this->exactly(2))->method('__invoke');
        $this->eventDispatcher->addListener(ProductWrittenEvent::NAME, $listener);
        $this->eventDispatcher->addListener(ProductManufacturerWrittenEvent::NAME, $listener);

        $this->repository->create([
            [
                'id' => $id->toString(),
                'name' => 'Test',
                'price' => 10,
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'manufacturer' => ['name' => 'test'],
            ],
        ], TranslationContext::createDefaultContext());

        //validate that nested events are triggered
        $listener = $this->getMockBuilder(CallableClass::class)->getMock();
        $listener->expects($this->exactly(2))->method('__invoke');
        $this->eventDispatcher->addListener(ProductBasicLoadedEvent::NAME, $listener);
        $this->eventDispatcher->addListener(ProductManufacturerBasicLoadedEvent::NAME, $listener);

        $products = $this->repository->readBasic([$id->toString()], TranslationContext::createDefaultContext());

        //check only provided id loaded
        $this->assertCount(1, $products);
        $this->assertTrue($products->has($id->toString()));

        /** @var ProductBasicStruct $product */
        $product = $products->get($id->toString());

        //check data loading is as expected
        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertEquals($id->toString(), $product->getId());
        $this->assertEquals('Test', $product->getName());

        $this->assertInstanceOf(ProductManufacturerBasicStruct::class, $product->getManufacturer());

        //check nested element loaded
        $manufacturer = $product->getManufacturer();
        $this->assertEquals('test', $manufacturer->getName());
    }

    public function testReadAndWriteProductPriceRules()
    {
        $id = Uuid::uuid4();
        $data = [
            'id' => $id->toString(),
            'name' => 'price test',
            'price' => 100,
            'manufacturer' => ['name' => 'test'],
            'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
            'prices' => json_encode([
                'H_D_E' => 5,
                'H_D' => 10,
                'H' => 15,
            ]),
        ];

        $this->repository->create([$data], TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$id->toString()], TranslationContext::createDefaultContext());

        $this->assertInstanceOf(ProductBasicCollection::class, $products);
        $this->assertCount(1, $products);
        $this->assertTrue($products->has($id->toString()));

        $product = $products->get($id->toString());

        /* @var ProductBasicStruct $product */
        $this->assertEquals($id->toString(), $product->getId());

        $this->assertEquals(100, $product->getPrice());
        $this->assertEquals(
            ['H_D_E' => 5, 'H_D' => 10, 'H' => 15],
            $product->getPrices()
        );
    }

    public function testPriceRulesSorting()
    {
        $id = Uuid::uuid4();
        $id2 = Uuid::uuid4();
        $id3 = Uuid::uuid4();

        $data = [
            [
                'id' => $id->toString(),
                'name' => 'price test 1',
                'price' => 100,
                'manufacturer' => ['name' => 'test'],
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'prices' => json_encode(['H_D_E' => 15]),
            ],
            [
                'id' => $id2->toString(),
                'name' => 'price test 2',
                'price' => 500,
                'manufacturer' => ['name' => 'test'],
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'prices' => json_encode(['H_D_E' => 5]),
            ],
            [
                'id' => $id3->toString(),
                'name' => 'price test 3',
                'price' => 500,
                'manufacturer' => ['name' => 'test'],
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'prices' => json_encode(['H_D_E' => 10]),
            ],
        ];

        $this->repository->create($data, TranslationContext::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('product.prices', FieldSorting::ASCENDING));

        /** @var IdSearchResult $products */
        $products = $this->repository->searchIds($criteria, TranslationContext::createDefaultContext());

        $this->assertEquals(
            [$id2->toString(), $id3->toString(), $id->toString()],
            $products->getIds()
        );

        $criteria = new Criteria();
        $criteria->addSorting(new FieldSorting('product.prices', FieldSorting::DESCENDING));

        /** @var IdSearchResult $products */
        $products = $this->repository->searchIds($criteria, TranslationContext::createDefaultContext());

        $this->assertEquals(
            [$id->toString(), $id3->toString(), $id2->toString()],
            $products->getIds()
        );
    }

    public function testVariantInheritancePriceAndName()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentPrice = 10;
        $parentName = 'T-shirt';
        $greenPrice = 12;

        $redName = 'Red shirt';

        $products = [
            [
                'id' => $parentId,
                'name' => $parentName,
                'price' => $parentPrice,
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'manufacturer' => ['name' => 'test'],
            ],

            //price should be inherited
            ['id' => $redId, 'name' => $redName, 'parentId' => $parentId],

            //name should be inherited
            ['id' => $greenId, 'price' => $greenPrice, 'parentId' => $parentId],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$redId, $greenId], TranslationContext::createDefaultContext());
        $parents = $this->repository->readBasic([$parentId], TranslationContext::createDefaultContext());

        $this->assertTrue($parents->has($parentId));
        $this->assertTrue($products->has($redId));
        $this->assertTrue($products->has($greenId));

        /** @var ProductBasicStruct $parent */
        $parent = $parents->get($parentId);

        /** @var ProductBasicStruct $red */
        $red = $products->get($redId);

        /** @var ProductBasicStruct $green */
        $green = $products->get($greenId);

        $this->assertEquals($parentPrice, $parent->getPrice());
        $this->assertEquals($parentName, $parent->getName());

        $this->assertEquals($parentPrice, $red->getPrice());
        $this->assertEquals($redName, $red->getName());

        $this->assertEquals($greenPrice, $green->getPrice());
        $this->assertEquals($parentName, $green->getName());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($parentId)->getBytes()]);
        $this->assertEquals($parentPrice, $row['price']);
        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromString($parentId)->getBytes()]);
        $this->assertEquals($parentName, $row['name']);

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($redId)->getBytes()]);
        $this->assertNull($row['price']);
        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromString($redId)->getBytes()]);
        $this->assertEquals($redName, $row['name']);

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($greenId)->getBytes()]);
        $this->assertEquals($greenPrice, $row['price']);
        $row = $this->connection->fetchAssoc('SELECT * FROM product_translation WHERE product_id = :id', ['id' => Uuid::fromString($greenId)->getBytes()]);
        $this->assertEmpty($row);
    }

    public function testInsertAndUpdateInOneStep()
    {
        $id = Uuid::uuid4()->toString();

        $data = [
            ['id' => $id, 'name' => 'Insert', 'price' => 10, 'tax' => ['name' => 'test', 'rate' => 10], 'manufacturer' => ['name' => 'test']],
            ['id' => $id, 'name' => 'Update', 'price' => 12],
        ];

        $this->repository->upsert($data, TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$id], TranslationContext::createDefaultContext());
        $this->assertTrue($products->has($id));

        /** @var ProductBasicStruct $product */
        $product = $products->get($id);

        $this->assertEquals('Update', $product->getName());
        $this->assertEquals(12, $product->getPrice());

        $count = $this->connection->fetchColumn('SELECT COUNT(id) FROM product');
        $this->assertEquals(1, $count);
    }

    public function testSwitchVariantToFullProduct()
    {
        $id = Uuid::uuid4()->toString();
        $child = Uuid::uuid4()->toString();

        $data = [
            ['id' => $id, 'name' => 'Insert', 'price' => 10, 'tax' => ['name' => 'test', 'rate' => 10], 'manufacturer' => ['name' => 'test']],
            ['id' => $child, 'parentId' => $id, 'name' => 'Update', 'price' => 12],
        ];

        $this->repository->upsert($data, TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$id, $child], TranslationContext::createDefaultContext());
        $this->assertTrue($products->has($id));
        $this->assertTrue($products->has($child));

        $raw = $this->connection->fetchAll('SELECT * FROM product');
        $this->assertCount(2, $raw);

        $data = [
            [
                'id' => $child,
                'parentId' => null,
            ],
        ];

        $e = null;
        try {
            $this->repository->upsert($data, TranslationContext::createDefaultContext());
        } catch (\Exception $e) {
        }
        $this->assertInstanceOf(WriteStackException::class, $e);

        /* @var WriteStackException $e */
        $this->assertArrayHasKey('/taxId', $e->toArray());
        $this->assertArrayHasKey('/manufacturerId', $e->toArray());
        $this->assertArrayHasKey('/price', $e->toArray());
        $this->assertArrayHasKey('/translations', $e->toArray());

        $data = [
            [
                'id' => $child,
                'parentId' => null,
                'name' => 'Child transformed to parent',
                'price' => 13,
                'tax' => ['name' => 'test', 'rate' => 15],
                'manufacturer' => ['name' => 'test3'],
            ],
        ];

        $this->repository->upsert($data, TranslationContext::createDefaultContext());

        $raw = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', [
            'id' => Uuid::fromString($child)->getBytes(),
        ]);

        $this->assertNull($raw['parent_id']);

        $products = $this->repository->readBasic([$child], TranslationContext::createDefaultContext());
        $product = $products->get($child);

        /* @var ProductBasicStruct $product */
        $this->assertEquals('Child transformed to parent', $product->getName());
        $this->assertEquals(13, $product->getPrice());
        $this->assertEquals('test3', $product->getManufacturer()->getName());
        $this->assertEquals(15, $product->getTax()->getRate());
    }

    public function testVariantInheritanceWithTax()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentTax = Uuid::uuid4()->toString();
        $greenTax = Uuid::uuid4()->toString();

        $products = [
            [
                'id' => $parentId,
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'name' => 'parent',
                'tax' => ['id' => $parentTax, 'rate' => 13, 'name' => 'green'],
            ],

            //price should be inherited
            ['id' => $redId, 'parentId' => $parentId],

            //name should be inherited
            ['id' => $greenId, 'parentId' => $parentId, 'tax' => ['id' => $greenTax, 'rate' => 13, 'name' => 'green']],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$redId, $greenId], TranslationContext::createDefaultContext());
        $parents = $this->repository->readBasic([$parentId], TranslationContext::createDefaultContext());

        $this->assertTrue($parents->has($parentId));
        $this->assertTrue($products->has($redId));
        $this->assertTrue($products->has($greenId));

        /** @var ProductBasicStruct $parent */
        $parent = $parents->get($parentId);

        /** @var ProductBasicStruct $red */
        $red = $products->get($redId);

        /** @var ProductBasicStruct $green */
        $green = $products->get($greenId);

        $this->assertEquals($parentTax, $parent->getTax()->getId());
        $this->assertEquals($parentTax, $red->getTax()->getId());
        $this->assertEquals($greenTax, $green->getTax()->getId());

        $this->assertEquals($parentTax, $parent->getTaxId());
        $this->assertEquals($parentTax, $red->getTaxId());
        $this->assertEquals($greenTax, $green->getTaxId());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($parentId)->getBytes()]);
        $this->assertEquals(10, $row['price']);
        $this->assertEquals($parentTax, Uuid::fromBytes($row['tax_id'])->toString());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($redId)->getBytes()]);
        $this->assertNull($row['price']);
        $this->assertNull($row['tax_id']);

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($greenId)->getBytes()]);
        $this->assertNull($row['price']);
        $this->assertEquals($greenTax, Uuid::fromBytes($row['tax_id'])->toString());
    }

    public function testWriteProductWithSameTaxes()
    {
        $this->connection->executeUpdate('DELETE FROM tax');
        $tax = ['id' => Uuid::uuid4()->toString(), 'rate' => 19, 'name' => 'test'];

        $data = [
            ['name' => 'test', 'tax' => $tax, 'price' => 10, 'manufacturer' => ['name' => 'test']],
            ['name' => 'test', 'tax' => $tax, 'price' => 10, 'manufacturer' => ['name' => 'test']],
            ['name' => 'test', 'tax' => $tax, 'price' => 10, 'manufacturer' => ['name' => 'test']],
            ['name' => 'test', 'tax' => $tax, 'price' => 10, 'manufacturer' => ['name' => 'test']],
            ['name' => 'test', 'tax' => $tax, 'price' => 10, 'manufacturer' => ['name' => 'test']],
        ];

        $written = $this->repository->create($data, TranslationContext::createDefaultContext());

        /** @var TaxWrittenEvent $taxes */
        $taxes = $written->getEventByDefinition(TaxDefinition::class);
        $this->assertInstanceOf(TaxWrittenEvent::class, $taxes);
        $this->assertCount(1, array_unique($taxes->getIds()));
    }

    public function testVariantInheritanceWithMedia()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentMedia = Uuid::uuid4()->toString();
        $greenMedia = Uuid::uuid4()->toString();

        $products = [
            [
                'id' => $parentId,
                'name' => 'T-shirt',
                'price' => 10,
                'manufacturer' => ['name' => 'test'],
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'media' => [
                    [
                        'id' => $parentMedia,
                        'media' => [
                            'id' => $parentMedia,
                            'fileName' => 'test_file.jpg',
                            'mimeType' => 'test_file',
                            'name' => 'test file',
                            'fileSize' => 1,
                            'album' => [
                                'id' => $parentMedia,
                                'name' => 'test album',
                            ],
                        ],
                    ],
                ],
            ],
            ['id' => $redId, 'parentId' => $parentId, 'name' => 'red'],
            [
                'id' => $greenId,
                'parentId' => $parentId,
                'name' => 'green',
                'media' => [
                    [
                        'id' => $greenMedia,
                        'media' => [
                            'id' => $greenMedia,
                            'fileName' => 'test_file.jpg',
                            'mimeType' => 'test_file',
                            'name' => 'test file',
                            'fileSize' => 1,
                            'albumId' => $parentMedia,
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $products = $this->repository->readDetail([$redId, $greenId], TranslationContext::createDefaultContext());
        $parents = $this->repository->readDetail([$parentId], TranslationContext::createDefaultContext());

        $this->assertTrue($parents->has($parentId));
        $this->assertTrue($products->has($redId));
        $this->assertTrue($products->has($greenId));

        /** @var ProductDetailStruct $parent */
        $parent = $parents->get($parentId);

        /** @var ProductDetailStruct $green */
        $green = $products->get($greenId);

        /** @var ProductDetailStruct $red */
        $red = $products->get($redId);

        $this->assertCount(1, $parent->getMedia());
        $this->assertTrue($parent->getMedia()->has($parentMedia));

        $this->assertCount(1, $green->getMedia());
        $this->assertTrue($green->getMedia()->has($greenMedia));

        $this->assertCount(1, $red->getMedia());
        $this->assertTrue($red->getMedia()->has($parentMedia));

        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromString($parentId)->getBytes()]);
        $this->assertEquals($parentMedia, Uuid::fromBytes($row['media_id'])->toString());

        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromString($redId)->getBytes()]);
        $this->assertEmpty($row['media_id']);

        $row = $this->connection->fetchAssoc('SELECT * FROM product_media WHERE product_id = :id', ['id' => Uuid::fromString($greenId)->getBytes()]);
        $this->assertEquals($greenMedia, Uuid::fromBytes($row['media_id'])->toString());
    }

    public function testVariantInheritanceWithCategories()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentCategory = Uuid::uuid4()->toString();
        $greenCategory = Uuid::uuid4()->toString();

        $products = [
            [
                'id' => $parentId,
                'name' => 'T-shirt',
                'price' => 10,
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'manufacturer' => ['name' => 'test'],
                'categories' => [
                    ['id' => $parentCategory, 'name' => 'parent'],
                ],
            ],
            ['id' => $redId, 'parentId' => $parentId, 'name' => 'red'],
            [
                'id' => $greenId,
                'parentId' => $parentId,
                'name' => 'green',
                'categories' => [
                    ['id' => $greenCategory, 'name' => 'green'],
                ],
            ],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $products = $this->repository->readDetail([$redId, $greenId], TranslationContext::createDefaultContext());
        $parents = $this->repository->readDetail([$parentId], TranslationContext::createDefaultContext());

        $this->assertTrue($parents->has($parentId));
        $this->assertTrue($products->has($redId));
        $this->assertTrue($products->has($greenId));

        /** @var ProductDetailStruct $parent */
        $parent = $parents->get($parentId);

        /** @var ProductDetailStruct $green */
        $green = $products->get($greenId);

        /** @var ProductDetailStruct $red */
        $red = $products->get($redId);

        $this->assertEquals([$parentCategory], $parent->getCategoryIds());
        $this->assertEquals([$parentCategory], $red->getCategoryIds());
        $this->assertEquals([$greenCategory], $green->getCategoryIds());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($parentId)->getBytes()]);
        $this->assertContains($parentCategory, json_decode($row['category_tree'], true));
        $this->assertEquals($parentId, Uuid::fromBytes($row['category_join_id'])->toString());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($redId)->getBytes()]);
        $this->assertNull($row['category_tree']);
        $this->assertEquals($parentId, Uuid::fromBytes($row['category_join_id'])->toString());

        $row = $this->connection->fetchAssoc('SELECT * FROM product WHERE id = :id', ['id' => Uuid::fromString($greenId)->getBytes()]);
        $this->assertContains($greenCategory, json_decode($row['category_tree'], true));
        $this->assertEquals($greenId, Uuid::fromBytes($row['category_join_id'])->toString());
    }

    public function testSearchByInheritedName()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentPrice = 10;
        $parentName = 'T-shirt';
        $greenPrice = 12;
        $redName = 'Red shirt';

        $products = [
            ['id' => $parentId, 'name' => $parentName, 'manufacturer' => ['name' => 'test'], 'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9', 'price' => $parentPrice],

            //price should be inherited
            ['id' => $redId,    'name' => $redName, 'parentId' => $parentId],

            //name should be inherited
            ['id' => $greenId,  'price' => $greenPrice, 'parentId' => $parentId],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('product.name', $parentName));

        $products = $this->repository->search($criteria, TranslationContext::createDefaultContext());
        $this->assertCount(2, $products);
        $this->assertTrue($products->has($parentId));
        $this->assertTrue($products->has($greenId));

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('product.name', $redName));

        $products = $this->repository->search($criteria, TranslationContext::createDefaultContext());
        $this->assertCount(1, $products);
        $this->assertTrue($products->has($redId));
    }

    public function testSearchByInheritedPrice()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentPrice = 10;
        $parentName = 'T-shirt';
        $greenPrice = 12;
        $redName = 'Red shirt';

        $products = [
            ['id' => $parentId, 'manufacturer' => ['name' => 'test'], 'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9', 'name' => $parentName, 'price' => $parentPrice],

            //price should be inherited
            ['id' => $redId,    'name' => $redName, 'parentId' => $parentId],

            //name should be inherited
            ['id' => $greenId,  'price' => $greenPrice, 'parentId' => $parentId],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('product.price', $parentPrice));

        $products = $this->repository->search($criteria, TranslationContext::createDefaultContext());
        $this->assertCount(2, $products);
        $this->assertTrue($products->has($parentId));
        $this->assertTrue($products->has($redId));

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('product.price', $greenPrice));

        $products = $this->repository->search($criteria, TranslationContext::createDefaultContext());
        $this->assertCount(1, $products);
        $this->assertTrue($products->has($greenId));
    }

    public function testSearchCategoriesWithProductsUseInheritance()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentPrice = 10;
        $parentName = 'T-shirt';
        $greenPrice = 12;
        $redName = 'Red shirt';

        $categoryId = Uuid::uuid4()->toString();

        $products = [
            [
                'id' => $parentId,
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'name' => $parentName,
                'price' => $parentPrice,
                'manufacturer' => ['name' => 'test'],
                'categories' => [
                    ['id' => $categoryId, 'name' => 'test'],
                ],
            ],

            //price should be inherited
            ['id' => $redId,    'name' => $redName, 'parentId' => $parentId],

            //name should be inherited
            ['id' => $greenId,  'price' => $greenPrice, 'parentId' => $parentId],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('category.products.price', $greenPrice));

        $repository = $this->container->get(CategoryRepository::class);
        $categories = $repository->searchIds($criteria, TranslationContext::createDefaultContext());

        $this->assertEquals(1, $categories->getTotal());
        $this->assertContains($categoryId, $categories->getIds());

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('category.products.price', $parentPrice));
        $criteria->addFilter(new TermQuery('category.products.parentId', null));

        $repository = $this->container->get(CategoryRepository::class);
        $categories = $repository->searchIds($criteria, TranslationContext::createDefaultContext());

        $this->assertEquals(1, $categories->getTotal());
        $this->assertContains($categoryId, $categories->getIds());
    }

    public function testSearchManufacturersWithProductsUseInheritance()
    {
        $redId = Uuid::uuid4()->toString();
        $greenId = Uuid::uuid4()->toString();
        $parentId = Uuid::uuid4()->toString();

        $parentPrice = 10;
        $parentName = 'T-shirt';
        $greenPrice = 12;
        $redName = 'Red shirt';

        $manufacturerId = Uuid::uuid4()->toString();
        $manufacturerId2 = Uuid::uuid4()->toString();

        $products = [
            [
                'id' => $parentId,
                'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                'name' => $parentName,
                'price' => $parentPrice,
                'manufacturer' => [
                    'id' => $manufacturerId,
                    'name' => 'test',
                ],
            ],
            //price should be inherited
            [
                'id' => $redId,
                'name' => $redName,
                'parentId' => $parentId,
                'manufacturer' => [
                    'id' => $manufacturerId2,
                    'name' => 'test',
                ],
            ],

            //manufacturer should be inherited
            ['id' => $greenId, 'price' => $greenPrice, 'parentId' => $parentId],
        ];

        $this->repository->create($products, TranslationContext::createDefaultContext());

        $criteria = new Criteria();
        $criteria->addFilter(new TermQuery('product_manufacturer.products.price', $greenPrice));

        $repository = $this->container->get(ProductManufacturerRepository::class);
        $result = $repository->searchIds($criteria, TranslationContext::createDefaultContext());

        $this->assertEquals(1, $result->getTotal());
        $this->assertContains($manufacturerId, $result->getIds());
    }

    public function testWriteProductOverCategories()
    {
        $productId = Uuid::uuid4()->toString();
        $categoryId = Uuid::uuid4()->toString();

        $categories = [
            [
                'id' => $categoryId,
                'name' => 'Cat1',
                'products' => [
                    [
                        'id' => $productId,
                        'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                        'name' => 'test',
                        'price' => 10,
                        'manufacturer' => ['name' => 'test'],
                    ],
                ],
            ],
        ];

        $repository = $this->container->get(CategoryRepository::class);

        $repository->create($categories, TranslationContext::createDefaultContext());

        $products = $this->repository->readDetail([$productId], TranslationContext::createDefaultContext());

        $this->assertCount(1, $products);
        $this->assertTrue($products->has($productId));

        /** @var ProductBasicStruct $product */
        $product = $products->get($productId);

        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertContains($categoryId, $product->getCategoryTree());
    }

    public function testWriteProductOverManufacturer()
    {
        $productId = Uuid::uuid4()->toString();
        $manufacturerId = Uuid::uuid4()->toString();

        $manufacturers = [
            [
                'id' => $manufacturerId,
                'name' => 'Manufacturer',
                'products' => [
                    [
                        'id' => $productId,
                        'name' => 'test',
                        'taxId' => '49260353-68e3-4d9f-a695-e017d7a231b9',
                        'manufacturerId' => $manufacturerId,
                        'price' => 10,
                    ],
                ],
            ],
        ];

        $repository = $this->container->get(ProductManufacturerRepository::class);

        $repository->create($manufacturers, TranslationContext::createDefaultContext());

        $products = $this->repository->readBasic([$productId], TranslationContext::createDefaultContext());

        $this->assertCount(1, $products);
        $this->assertTrue($products->has($productId));

        /** @var ProductBasicStruct $product */
        $product = $products->get($productId);

        $this->assertInstanceOf(ProductBasicStruct::class, $product);
        $this->assertEquals($manufacturerId, $product->getManufacturerId());
    }
}

class CallableClass
{
    public function __invoke()
    {
    }
}