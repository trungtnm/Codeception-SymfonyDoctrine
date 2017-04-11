<?php

class SampleTest extends \Codeception\Test\Unit 
{
	/**
     * @var \UnitTester
     */
    protected $tester;

	public function sampleTest(UnitTester $I)
    {
		$someClass = 'someClassName';
		$data = []; // some data
		// normal way -> use `default` connection
        $I->seeInRepository(someClass, $data);

        // new way - use `mycustom` connection
        $I->seeInRepository(someClass, $data, 'mycustom');
        /**
        * And so so on, you can use original Codeception Doctrine2 module methods with extra 
        * parameter which specific your Doctrine connection as last argument.
        * 
		*   $I->dontSeeInRepository
		*	$I->flushToDatabase
		*	$I->grabFromRepository
		*	$I->haveFakeRepository
		*	$I->haveInRepository
		*	$I->persistEntity
		*	$I->seeInRepository
        *
        **/

    }
}