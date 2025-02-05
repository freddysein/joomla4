<?php
/**
 * @package     FOF
 * @copyright   Copyright (c)2010-2019 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license     GNU GPL version 2 or later
 */

namespace FOF30\Toolbar;

use FOF30\Container\Container;
use FOF30\Controller\Controller;
use FOF30\Toolbar\Exception\MissingAttribute;
use FOF30\Toolbar\Exception\UnknownButtonType;
use FOF30\Utils\StringHelper;
use FOF30\View\DataView\DataViewInterface;
use FOF30\View\View;
use Joomla\Utilities\ArrayHelper;
use JText;
use JToolBarHelper;

defined('_JEXEC') or die;

/**
 * The Toolbar class renders the back-end component title area and the back-
 * and front-end toolbars.
 *
 * @since    1.0
 */
class Toolbar
{
	/** @var   Container   Component container */
	protected $container = null;

	/** @var   array   Permissions map, see the __construct method for more information */
	public $perms = array();

	/** @var   array   The links to be rendered in the toolbar */
	protected $linkbar = array();

	/** @var   bool   Should I render the submenu in the front-end? */
	protected $renderFrontendSubmenu = false;

	/** @var   bool   Should I render buttons in the front-end? */
	protected $renderFrontendButtons = false;

	/** @var   bool  Should I use the configuration file (fof.xml) of the component? */
	protected $useConfigurationFile = false;

	/** @var  null|bool  Are we rendering a data-aware view? */
	protected $isDataView = null;

	/**
	 * Public constructor.
	 *
	 * The $config array can contain the following optional values:
	 *
	 * renderFrontendButtons	bool	Should I render buttons in the front-end of the component?
	 * renderFrontendSubmenu	bool	Should I render the submenu in the front-end of the component?
	 * useConfigurationFile		bool	Should we use the configuration file (fof.xml) of the component?
	 *
	 * @param   Container  $c       The container for the component
	 * @param   array      $config  The configuration overrides, see above
	 */
	public function __construct(Container $c, array $config = array())
	{
		// Store the container reference in this object
		$this->container = $c;

		// Get a reference to some useful objects
		$input = $this->container->input;
		$platform = $this->container->platform;

		// Get default permissions (can be overriden by the view)
		$perms = (object)array(
			'manage'    => $this->container->platform->authorise('core.manage', $input->getCmd('option', 'com_foobar')),
			'create'    => $this->container->platform->authorise('core.create', $input->getCmd('option', 'com_foobar')),
			'edit'      => $this->container->platform->authorise('core.edit', $input->getCmd('option', 'com_foobar')),
			'editstate' => $this->container->platform->authorise('core.edit.state', $input->getCmd('option', 'com_foobar')),
			'delete'    => $this->container->platform->authorise('core.delete', $input->getCmd('option', 'com_foobar')),
		);

		// Save front-end toolbar and submenu rendering flags if present in the config
		if (array_key_exists('renderFrontendButtons', $config))
		{
			$this->renderFrontendButtons = $config['renderFrontendButtons'];
		}

		if (array_key_exists('renderFrontendSubmenu', $config))
		{
			$this->renderFrontendSubmenu = $config['renderFrontendSubmenu'];
		}

		// If not in the administrative area, load the JToolbarHelper
		if (!$platform->isBackend())
		{
			// Needed for tests, so we can inject our "special" helper class
			if (!class_exists('\\JToolbarHelper'))
			{
				$platformDirs = $platform->getPlatformBaseDirs();
				$path = $platformDirs['root'] . '/administrator/includes/toolbar.php';
				require_once $path;
			}

			// Things to do if we have to render a front-end toolbar
			if ($this->renderFrontendButtons)
			{
				// Load back-end toolbar language files in front-end
				$platform->loadTranslations('');

				// Needed for tests (we can fake we're not in the backend, but we are still in CLI!)
				if (!$platform->isCli())
				{
					// Load the core Javascript
					\JHtml::_('behavior.core');
					\JHtml::_('jquery.framework', true);
				}
			}
		}

		// Store permissions in the local toolbar object
		$this->perms = $perms;
	}

	/**
	 * Renders the toolbar for the current view and task
	 *
	 * @param   string   $view  The view of the component
	 * @param   string   $task  The exact task of the view
	 *
	 * @return  void
	 */
	public function renderToolbar($view = null, $task = null)
	{
		$input = $this->container->input;

		// If tmpl=component the default behaviour is to not render the toolbar
		if ($input->getCmd('tmpl', '') == 'component')
		{
			$render_toolbar = false;
		}
		else
		{
			$render_toolbar = true;
		}

		// If there is a render_toolbar=0 in the URL, do not render a toolbar
		$render_toolbar = $input->getBool('render_toolbar', $render_toolbar);

		if (!$render_toolbar)
		{
			return;
		}

		// Get the view and task
		$controller = $this->container->dispatcher->getController();
		$autoDetectedView = 'cpanel';
		$autoDetectedTask = 'main';

		if (is_object($controller) && ($controller instanceof Controller))
		{
			$autoDetectedView = $controller->getName();
			$autoDetectedTask = $controller->getTask();
		}

		if (empty($view))
		{
			$view = $input->getCmd('view', $autoDetectedView);
		}

		if (empty($task))
		{
			$task = $input->getCmd('task', $autoDetectedTask);
		}

		// If there is a fof.xml toolbar configuration use it and return
		$view = $this->container->inflector->pluralize($view);
		$toolbarConfig = $this->container->appConfig->get('views.' . ucfirst($view) . '.toolbar.' . $task);

		$oldValues = array(
			'renderFrontendButtons' => $this->renderFrontendButtons,
			'renderFrontendSubmenu' => $this->renderFrontendSubmenu,
			'useConfigurationFile'  => $this->useConfigurationFile,
		);

		$newValues = array(
			'renderFrontendButtons' => $this->container->appConfig->get(
				'views.' . ucfirst($view) . '.config.renderFrontendButtons',
				$oldValues['renderFrontendButtons']
			),
			'renderFrontendSubmenu' => $this->container->appConfig->get(
				'views.' . ucfirst($view) . '.config.renderFrontendSubmenu',
				$oldValues['renderFrontendSubmenu']
			),
			'useConfigurationFile'  => $this->container->appConfig->get(
				'views.' . ucfirst($view) . '.config.useConfigurationFile',
				$oldValues['useConfigurationFile']
			),
		);

		foreach ($newValues as $k => $v)
		{
			$this->$k = $v;
		}

		if (!empty($toolbarConfig) && $this->useConfigurationFile)
		{
			$this->renderFromConfig($toolbarConfig);

			return;
		}

		// Check for an onViewTask method
		$methodName = 'on' . ucfirst($view) . ucfirst($task);

		if (method_exists($this, $methodName))
		{
			$this->$methodName();

			return;
		}

		// Check for an onView method
		$methodName = 'on' . ucfirst($view);

		if (method_exists($this, $methodName))
		{
			$this->$methodName();

			return;
		}

		// Check for an onTask method
		$methodName = 'on' . ucfirst($task);

		if (method_exists($this, $methodName))
		{
			$this->$methodName();

			return;
		}
	}

	/**
	 * Renders the toolbar for the component's Control Panel page
	 *
	 * @return  void
	 */
	public function onCpanelsBrowse()
	{
		if ($this->container->platform->isBackend() || $this->renderFrontendSubmenu)
		{
			$this->renderSubmenu();
		}

		if (!$this->container->platform->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		$option = $this->container->componentName;

		JToolBarHelper::title(JText::_(strtoupper($option)), str_replace('com_', '', $option));

		if (!$this->isDataView())
		{
			return;
		}

		JToolBarHelper::preferences($option);
	}

	/**
	 * Renders the toolbar for the component's Browse pages (the plural views)
	 *
	 * @return  void
	 */
	public function onBrowse()
	{
		// On frontend, buttons must be added specifically
		if ($this->container->platform->isBackend() || $this->renderFrontendSubmenu)
		{
			$this->renderSubmenu();
		}

		if (!$this->container->platform->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		// Setup
		$option = $this->container->componentName;
		$view   = $this->container->input->getCmd('view', 'cpanel');

		// Set toolbar title
		$subtitle_key = strtoupper($option . '_TITLE_' . $view);
		JToolBarHelper::title(JText::_(strtoupper($option)) . ': ' . JText::_($subtitle_key), str_replace('com_', '', $option));

		if (!$this->isDataView())
		{
			return;
		}

		// Add toolbar buttons
		if ($this->perms->create)
		{
			JToolBarHelper::addNew();
		}

		if ($this->perms->edit)
		{
			JToolBarHelper::editList();
		}

		if ($this->perms->create || $this->perms->edit)
		{
			JToolBarHelper::divider();
		}

		// Published buttons are only added if there is a enabled field in the table
		try
		{
			$model = $this->container->factory->model($view);

			if ($model->hasField('enabled') && $this->perms->editstate)
			{
				JToolBarHelper::publishList();
				JToolBarHelper::unpublishList();
				JToolBarHelper::divider();
			}
		}
		catch (\Exception $e)
		{
			// Yeah. Let's not add the buttons if we can't load the model...
		}

		if ($this->perms->delete)
		{
			$msg = JText::_($option . '_CONFIRM_DELETE');
			JToolBarHelper::deleteList(strtoupper($msg));
		}

		// A Check-In button is only added if there is a locked_on field in the table
		try
		{
			$model = $this->container->factory->model($view);

			if ($model->hasField('locked_on') && $this->perms->edit)
			{
				JToolBarHelper::checkin();
			}

		}
		catch (\Exception $e)
		{
			// Yeah. Let's not add the button if we can't load the model...
		}
	}

	/**
	 * Renders the toolbar for the component's Read pages
	 *
	 * @return  void
	 */
	public function onRead()
	{
		// On frontend, buttons must be added specifically
		if ($this->container->platform->isBackend() || $this->renderFrontendSubmenu)
		{
			$this->renderSubmenu();
		}

		if (!$this->container->platform->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		$option = $this->container->componentName;
		$componentName = str_replace('com_', '', $option);
		$view = $this->container->input->getCmd('view', 'cpanel');

		// Set toolbar title
		$subtitle_key = strtoupper($option . '_TITLE_' . $view . '_READ');
		JToolBarHelper::title(JText::_(strtoupper($option)) . ': ' . JText::_($subtitle_key), $componentName);

		if (!$this->isDataView())
		{
			return;
		}

		// Set toolbar icons
		JToolBarHelper::back();
	}

	/**
	 * Renders the toolbar for the component's Add pages
	 *
	 * @return  void
	 */
	public function onAdd()
	{
		// On frontend, buttons must be added specifically
		if (!$this->container->platform->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		$option = $this->container->componentName;
		$componentName = str_replace('com_', '', $option);
		$view = $this->container->input->getCmd('view', 'cpanel');

		// Set toolbar title
		$subtitle_key = strtoupper($option . '_TITLE_' . $this->container->inflector->pluralize($view)) . '_EDIT';
		JToolBarHelper::title(JText::_(strtoupper($option)) . ': ' . JText::_($subtitle_key), $componentName);

		if (!$this->isDataView())
		{
			return;
		}

		// Set toolbar icons
		if ($this->perms->edit || $this->perms->editown)
		{
			// Show the apply button only if I can edit the record, otherwise I'll return to the edit form and get a
			// 403 error since I can't do that
			JToolBarHelper::apply();
		}

		JToolBarHelper::save();

		if ($this->perms->create)
		{
			JToolBarHelper::custom('savenew', 'save-new.png', 'save-new_f2.png', 'JTOOLBAR_SAVE_AND_NEW', false);
		}

		JToolBarHelper::cancel();
	}

	/**
	 * Renders the toolbar for the component's Edit pages
	 *
	 * @return  void
	 */
	public function onEdit()
	{
		// On frontend, buttons must be added specifically
		if (!$this->container->platform->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		$this->onAdd();
	}

	/**
	 * Removes all links from the link bar
	 *
	 * @return  void
	 */
	public function clearLinks()
	{
		$this->linkbar = array();
	}

	/**
	 * Get the link bar's link definitions
	 *
	 * @return  array
	 */
	public function &getLinks()
	{
		return $this->linkbar;
	}

	/**
	 * Append a link to the link bar
	 *
	 * @param   string      $name   The text of the link
	 * @param   string|null $link   The link to render; set to null to render a separator
	 * @param   boolean     $active True if it's an active link
	 * @param   string|null $icon   Icon class (used by some renderers, like the Bootstrap renderer)
	 * @param   string|null $parent The parent element (referenced by name)) Thsi will create a dropdown list
	 *
	 * @return  void
	 */
	public function appendLink($name, $link = null, $active = false, $icon = null, $parent = '')
	{
		$linkDefinition = array(
			'name'   => $name,
			'link'   => $link,
			'active' => $active,
			'icon'   => $icon
		);

		if (empty($parent))
		{
			if (array_key_exists($name, $this->linkbar))
			{
				$this->linkbar[$name] = array_merge($this->linkbar[$name], $linkDefinition);

				// If there already are some children, I have to put this view link in the "items" array in the first place
				if (array_key_exists('items', $this->linkbar[$name]))
				{
					array_unshift($this->linkbar[$name]['items'], $linkDefinition);
				}
			}
			else
			{
				$this->linkbar[$name] = $linkDefinition;
			}
		}
		else
		{
			if (!array_key_exists($parent, $this->linkbar))
			{
				$parentElement = $linkDefinition;
				$parentElement['name'] = $parent;
				$parentElement['link'] = null;
				$this->linkbar[$parent] = $parentElement;
				$parentElement['items'] = array();
			}
			else
			{
				$parentElement = $this->linkbar[$parent];

				if (!array_key_exists('dropdown', $parentElement) && !empty($parentElement['link']))
				{
					$newSubElement = $parentElement;
					$parentElement['items'] = array($newSubElement);
				}
			}

			$parentElement['items'][] = $linkDefinition;
			$parentElement['dropdown'] = true;

			if ($active)
			{
				$parentElement['active'] = true;
			}

			$this->linkbar[$parent] = $parentElement;
		}
	}

	/**
	 * Prefixes (some people erroneously call this "prepend" – there is no such word) a link to the link bar
	 *
	 * @param   string      $name   The text of the link
	 * @param   string|null $link   The link to render; set to null to render a separator
	 * @param   boolean     $active True if it's an active link
	 * @param   string|null $icon   Icon class (used by some renderers, like the Bootstrap renderer)
	 *
	 * @return  void
	 */
	public function prefixLink($name, $link = null, $active = false, $icon = null)
	{
		$linkDefinition = array(
			'name'   => $name,
			'link'   => $link,
			'active' => $active,
			'icon'   => $icon
		);
		array_unshift($this->linkbar, $linkDefinition);
	}

	/**
	 * Renders the submenu (toolbar links) for all detected views of this component
	 *
	 * @return  void
	 */
	public function renderSubmenu()
	{
		$views = $this->getMyViews();

		if (empty($views))
		{
			return;
		}

		$activeView = $this->container->input->getCmd('view', 'cpanel');

		foreach ($views as $view)
		{
			// Get the view name
			$key = strtoupper($this->container->componentName) . '_TITLE_' . strtoupper($view);

			//Do we have a translation for this key?
			if (strtoupper(JText::_($key)) == $key)
			{
				$altview = $this->container->inflector->isPlural($view) ? $this->container->inflector->singularize($view) : $this->container->inflector->pluralize($view);
				$key2 = strtoupper($this->container->componentName) . '_TITLE_' . strtoupper($altview);

				// Maybe we have for the alternative view?
				if (strtoupper(JText::_($key2)) == $key2)
				{
					// Nope, let's use the raw name
					$name = ucfirst($view);
				}
				else
				{
					$name = JText::_($key2);
				}
			}
			else
			{
				$name = JText::_($key);
			}

			$link = 'index.php?option=' . $this->container->componentName . '&view=' . $view;

			$active = $view == $activeView;

			$this->appendLink($name, $link, $active);
		}
	}

	/**
	 * Automatically detects all views of the component
	 *
	 * @return  array  A list of all views, in the order to be displayed in the toolbar submenu
	 */
	protected function getMyViews()
	{
		$t_views = array();
		$using_meta = false;

		$componentPaths = $this->container->platform->getComponentBaseDirs($this->container->componentName);
		$searchPath = $componentPaths['main'] . '/View';
		$filesystem = $this->container->filesystem;

		$allFolders = $filesystem->folderFolders($searchPath);

		if (!empty($allFolders))
		{
			foreach ($allFolders as $folder)
			{
				$view = $folder;

				// View already added
				if (in_array($this->container->inflector->pluralize($view), $t_views))
				{
					continue;
				}

				// Do we have a 'skip.xml' file in there?
				$files = $filesystem->folderFiles($searchPath . '/' . $view, '^skip\.xml$');

				if (!empty($files))
				{
					continue;
				}

				// Do we have extra information about this view? (ie. ordering)
				$meta = $filesystem->folderFiles($searchPath . '/' . $view, '^metadata\.xml$');

				// Not found, do we have it inside the plural one?
				if (!$meta)
				{
					$plural = $this->container->inflector->pluralize($view);

					if (in_array($plural, $allFolders))
					{
						$view = $plural;
						$meta = $filesystem->folderFiles($searchPath . '/' . $view, '^metadata\.xml$');
					}
				}

				if (!empty($meta))
				{
					$using_meta = true;
					$xml = simplexml_load_file($searchPath . '/' . $view . '/' . $meta[0]);
					$order = (int)$xml->foflib->ordering;
				}
				else
				{
					// Next place. It's ok since the index are 0-based and count is 1-based

					if (!isset($to_order))
					{
						$to_order = array();
					}

					$order = count($to_order);
				}

				$view = $this->container->inflector->pluralize($view);

				$t_view = new \stdClass;
				$t_view->ordering = $order;
				$t_view->view = $view;

				$to_order[] = $t_view;
				$t_views[] = $view;
			}
		}

		$views = array();

		if (!empty($to_order))
		{
			if (class_exists('JArrayHelper'))
			{
				\JArrayHelper::sortObjects($to_order, 'ordering');
				$views = \JArrayHelper::getColumn($to_order, 'view');
			}
			else
			{
				ArrayHelper::sortObjects($to_order, 'ordering');
				$views = ArrayHelper::getColumn($to_order, 'view');
			}

		}

		// If not using the metadata file, let's put the cpanel view on top
		if (!$using_meta)
		{
			$cpanel = array_search('cpanels', $views);

			if ($cpanel !== false)
			{
				unset($views[$cpanel]);
				array_unshift($views, 'cpanels');
			}
		}

		return $views;
	}

	/**
	 * Return the front-end toolbar rendering flag
	 *
	 * @return  boolean
	 */
	public function getRenderFrontendButtons()
	{
		return $this->renderFrontendButtons;
	}

	/**
	 * @param boolean $renderFrontendButtons
	 */
	public function setRenderFrontendButtons($renderFrontendButtons)
	{
		$this->renderFrontendButtons = $renderFrontendButtons;
	}

	/**
	 * Return the front-end submenu rendering flag
	 *
	 * @return  boolean
	 */
	public function getRenderFrontendSubmenu()
	{
		return $this->renderFrontendSubmenu;
	}

	/**
	 * @param boolean $renderFrontendSubmenu
	 */
	public function setRenderFrontendSubmenu($renderFrontendSubmenu)
	{
		$this->renderFrontendSubmenu = $renderFrontendSubmenu;
	}

	/**
	 * Is the view we are rendering the toolbar for a data-aware view?
	 *
	 * @return  bool
	 */
	public function isDataView()
	{
		if (is_null($this->isDataView))
		{
			$this->isDataView = false;
			$controller = $this->container->dispatcher->getController();
			$view = null;

			if (is_object($controller) && ($controller instanceof Controller))
			{
				$view = $controller->getView();
			}

			if (is_object($view) && ($view instanceof View))
			{
				$this->isDataView = $view instanceof DataViewInterface;
			}
		}

		return $this->isDataView;
	}

	/**
	 * Render the toolbar from the configuration.
	 *
	 * @param   array  $toolbar  The toolbar definition
	 *
	 * @return  void
	 */
	private function renderFromConfig(array $toolbar)
	{
		$isBackend = $this->container->platform->isBackend();

		if ($isBackend || $this->renderFrontendSubmenu)
		{
			$this->renderSubmenu();
		}

		if (!$isBackend && !$this->renderFrontendButtons)
		{
			return;
		}

		if (!$this->isDataView())
		{
			return;
		}

		// Render each element
		foreach ($toolbar as $elementType => $elementAttributes)
		{
			$value = isset($elementAttributes['value']) ? $elementAttributes['value'] : null;
			$this->renderToolbarElement($elementType, $value, $elementAttributes);
		}

		return;
	}

	/**
	* Simplified default rendering without any attributes.
	*
	* @access	protected
	* @param	array	$tasks	Array of tasks.
	*
	* @return	void
	*/
	protected function renderToolbarElements($tasks)
	{
		foreach($tasks as $task)
			$this->renderToolbarElement($task);
	}

	/**
	 * Render a toolbar element.
	 *
	 * @param   string  $type        The element type.
	 * @param   mixed   $value       The element value.
	 * @param   array   $attributes  The element attributes.
	 *
	 * @return  void
	 *
	 * @codeCoverageIgnore
	 * @throws  \InvalidArgumentException
	 */
	private function renderToolbarElement($type, $value = null, array $attributes = array())
	{
		switch ($type)
		{
			case 'title':
				$icon  = isset($attributes['icon']) ? $attributes['icon'] : 'generic.png';
				if (isset($attributes['translate']))
				{
					$value = JText::_($value);
				}

				JToolbarHelper::title($value, $icon);
				break;

			case 'divider':
				JToolbarHelper::divider();
				break;

			case 'custom':
				$task = isset($attributes['task']) ? $attributes['task'] : '';
				$icon = isset($attributes['icon']) ? $attributes['icon'] : '';
				$iconOver = isset($attributes['icon_over']) ? $attributes['icon_over'] : '';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : '';
				$listSelect = isset($attributes['list_select']) ?
					StringHelper::toBool($attributes['list_select']) : true;

				JToolbarHelper::custom($task, $icon, $iconOver, $alt, $listSelect);
				break;

			case 'preview':
				$url = isset($attributes['url']) ? $attributes['url'] : '';
				$update_editors = isset($attributes['update_editors']) ?
					StringHelper::toBool($attributes['update_editors']) : false;

				JToolbarHelper::preview($url, $update_editors);
				break;

			case 'help':
				if (!isset($attributes['help']))
				{
					throw new MissingAttribute('help', 'help');
				}

				$ref = $attributes['help'];
				$com = isset($attributes['com']) ? StringHelper::toBool($attributes['com']) : false;
				$override = isset($attributes['override']) ? $attributes['override'] : null;
				$component = isset($attributes['component']) ? $attributes['component'] : null;

				JToolbarHelper::help($ref, $com, $override, $component);
				break;

			case 'back':
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_BACK';
				$href = isset($attributes['href']) ? $attributes['href'] : 'javascript:history.back();';

				JToolbarHelper::back($alt, $href);
				break;

			case 'media_manager':
				$directory = isset($attributes['directory']) ? $attributes['directory'] : '';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_UPLOAD';

				JToolbarHelper::media_manager($directory, $alt);
				break;

			case 'assign':
				$task = isset($attributes['task']) ? $attributes['task'] : 'assign';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_ASSIGN';

				JToolbarHelper::assign($task, $alt);
				break;

			case 'addNew':
			case 'new':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'create';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'add';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_NEW';
					$check = isset($attributes['check']) ?
						StringHelper::toBool($attributes['check']) : false;

					JToolbarHelper::addNew($task, $alt, $check);
				}

				break;

			case 'copy':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'create';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'copy';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JLIB_HTML_BATCH_COPY';
					$icon = isset($attributes['icon']) ? $attributes['icon'] : 'copy.png';
					$iconOver = isset($attributes['iconOver']) ? $attributes['iconOver'] : 'copy_f2.png';

					JToolBarHelper::custom($task, $icon, $iconOver, $alt, false);
				}

				break;

			case 'publish':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'publish';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_PUBLISH';
					$check = isset($attributes['check']) ?
						StringHelper::toBool($attributes['check']) : false;

					JToolbarHelper::publish($task, $alt, $check);
				}

				break;

			case 'publishList':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'publish';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_PUBLISH';

					JToolbarHelper::publishList($task, $alt);
				}

				break;

			case 'unpublish':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'unpublish';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_UNPUBLISH';
					$check = isset($attributes['check']) ?
						StringHelper::toBool($attributes['check']) : false;

					JToolbarHelper::unpublish($task, $alt, $check);
				}

				break;

			case 'unpublishList':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'unpublish';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_UNPUBLISH';

					JToolbarHelper::unpublishList($task, $alt);
				}

				break;

			case 'archiveList':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'archive';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_ARCHIVE';

					JToolbarHelper::archiveList($task, $alt);
				}

				break;

			case 'unarchiveList':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'unarchive';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_UNARCHIVE';

					JToolbarHelper::unarchiveList($task, $alt);
				}

				break;

			case 'edit':
			case 'editList':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'edit';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'edit';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_EDIT';

					JToolbarHelper::editList($task, $alt);
				}

				break;

			case 'editHtml':
				$task = isset($attributes['task']) ? $attributes['task'] : 'edit_source';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_EDIT_HTML';

				JToolbarHelper::editHtml($task, $alt);
				break;

			case 'editCss':
				$task = isset($attributes['task']) ? $attributes['task'] : 'edit_css';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_EDIT_CSS';

				JToolbarHelper::editCss($task, $alt);
				break;

			case 'deleteList':
			case 'delete':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'delete';

				if ($this->checkACL($area))
				{
					$msg = isset($attributes['msg']) ? $attributes['msg'] : '';
					$task = isset($attributes['task']) ? $attributes['task'] : 'remove';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_DELETE';

					JToolbarHelper::deleteList($msg, $task, $alt);
				}

				break;

			case 'trash':
				$area = isset($attributes['acl']) ? $attributes['acl'] : 'editstate';

				if ($this->checkACL($area))
				{
					$task = isset($attributes['task']) ? $attributes['task'] : 'trash';
					$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_TRASH';
					$check = isset($attributes['check']) ?
						StringHelper::toBool($attributes['check']) : true;

					JToolbarHelper::trash($task, $alt, $check);
				}

				break;

			case 'apply':
				$task = isset($attributes['task']) ? $attributes['task'] : 'apply';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_APPLY';

				JToolbarHelper::apply($task, $alt);
				break;

			case 'save':
				$task = isset($attributes['task']) ? $attributes['task'] : 'save';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_SAVE';

				JToolbarHelper::save($task, $alt);
				break;

			case 'savenew':
				$task = isset($attributes['task']) ? $attributes['task'] : 'savenew';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_SAVE_AND_NEW';
				$icon = isset($attributes['icon']) ? $attributes['icon'] : 'save-new.png';
				$iconOver = isset($attributes['iconOver']) ? $attributes['iconOver'] : 'save-new_f2.png';

				JToolBarHelper::custom($task, $icon, $iconOver, $alt, false);
				break;

			case 'save2new':
				$task = isset($attributes['task']) ? $attributes['task'] : 'save2new';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_SAVE_AND_NEW';

				JToolbarHelper::save2new($task, $alt);
				break;

			case 'save2copy':
				$task = isset($attributes['task']) ? $attributes['task'] : 'save2copy';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_SAVE_AS_COPY';
				JToolbarHelper::save2copy($task, $alt);
				break;

			case 'checkin':
				$task = isset($attributes['task']) ? $attributes['task'] : 'checkin';
				$alt = isset($attributes['alt']) ? $attributes['alt'] :'JTOOLBAR_CHECKIN';
				$check = isset($attributes['check']) ?
					StringHelper::toBool($attributes['check']) : true;

				JToolbarHelper::checkin($task, $alt, $check);
				break;

			case 'cancel':
				$task = isset($attributes['task']) ? $attributes['task'] : 'cancel';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JTOOLBAR_CANCEL';

				JToolbarHelper::cancel($task, $alt);
				break;

			case 'preferences':
				if (!isset($attributes['component']))
				{
					throw new MissingAttribute('component', 'preferences');
				}

				$component = $attributes['component'];
				$height = isset($attributes['height']) ? $attributes['height'] : '550';
				$width = isset($attributes['width']) ? $attributes['width'] : '875';
				$alt = isset($attributes['alt']) ? $attributes['alt'] : 'JToolbar_Options';
				$path = isset($attributes['path']) ? $attributes['path'] : '';

				JToolbarHelper::preferences($component, $height, $width, $alt, $path);
				break;

			default:
				throw new UnknownButtonType($type);
		}
	}

	/**
	 * Checks if the current user has enough privileges for the requested ACL privilege of a custom toolbar button.
	 *
	 * @param   string  $area  The ACL privilege as set up in the $this->perms object
	 *
	 * @return  boolean  True if the user has the ACL privilege specified
	 */
	protected function checkACL($area)
	{
		if (is_bool($area))
		{
			return $area;
		}

		if (in_array(strtolower($area), array('false','0','no','403')))
		{
			return false;
		}

		if (in_array(strtolower($area), array('true','1','yes')))
		{
			return true;
		}

		if (in_array(strtolower($area), array('guest')))
		{
			return $this->container->platform->getUser()->guest;
		}

		if (in_array(strtolower($area), array('user')))
		{
			return !$this->container->platform->getUser()->guest;
		}

		if (empty($area))
		{
			return true;
		}

		if (isset($this->perms->$area))
		{
			return $this->perms->$area;
		}

		return false;
	}
}
