<?php

use Fitak\Orm;
use Fitak\PostManager;
use Nette\Application\UI\Form;

class PostForm extends Form
{
	/** @var postManager @inject */
	public $postManager;

	private $orm;

	private $user;

	const SEMESTER = 'semester';

	public function __construct(Orm $orm, $user)
	{
		parent::__construct();

		$this->orm = $orm;
		$this->user = $user;
		$this->addText('message', 'zprava');

		$this->addSubmit('send', 'Vyhledat');
	}

	public function submitted(self $form)
	{
		$values = $form->getValues(TRUE);
		$message = $values['message'];

		$this->postManager = new PostManager($this->orm);
		$this->postManager->savePost($message, $this->user);

		$parameters = $this->getPresenter()->getParameters();
		unset($parameters['do']);

		$this->presenter->redirect('Search:', $parameters);
	}

}