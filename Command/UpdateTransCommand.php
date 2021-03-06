<?php

namespace BCC\ExtraToolsBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class UpdateTransCommand extends Command {

    private $_defaultDomain = 'messages';
    protected $_prefix;
    protected $output;
    protected $templatesMessages = array();
    protected $filesMessages = array();
    protected $mergedMessages = array();

    /**
     * @see Command
     */
    protected function configure() {
        $this
                ->setName('bcc:trans:update')
                ->setDescription('Update the translation file')
                ->setDefinition(array(
                    new InputArgument('locale', InputArgument::REQUIRED, 'The locale'),
                    new InputArgument('bundle', InputArgument::REQUIRED, 'The bundle where to load the messages'),
                    new InputOption(
                            'prefix', null, InputOption::VALUE_OPTIONAL,
                            'Override the default prefix', '__'
                    ),
                    new InputOption(
                            'dump-messages', null, InputOption::VALUE_NONE,
                            'Should the messages be dumped in the console'
                    ),
                    new InputOption(
                            'force', null, InputOption::VALUE_NONE,
                            'Should the update be done'
                    )
                ));
    }

    /**
     * @see Command
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->output = $output;

        $twig = $this->container->get('twig');
        
        if($input->getOption('force') !== true && $input->getOption('dump-messages') !== true){
            $this->output->writeln('You should choose option --force or --dump-messages');
        }
        else{
            // get bundle directory
            $foundBundle = $this->getApplication()->getKernel()->getBundle($input->getArgument('bundle'));
            
            // get prefix
            $this->_prefix = $input->getOption('prefix');
            
            // load messages from templates
            $finder = new Finder();
            $files = $finder->files()->name('*.html.twig')->in($foundBundle->getPath() . '/Resources/views/');
            foreach ($files as $file) {
                $this->output->writeln('Parsing : ' . $file->getPathname());
                $tree = $twig->parse($twig->tokenize(file_get_contents($file->getPathname())));
                $this->_crawlNode($tree);
            }

            // load messages from trans files
            $finder = new Finder();
            $files = $finder->files()->name('*.' . $input->getArgument('locale') . '.yml')->in($foundBundle->getPath() . '/Resources/translations');
            foreach ($files as $file) {
                $this->output->writeln('Parsing : ' . $file->getPathname());
                $yml = \Symfony\Component\Yaml\Yaml::load($file->getPathname());
                //get domain
                $domain = substr($file->getFileName(), 0, strrpos($file->getFileName(), $input->getArgument('locale') . '.yml') - 1);
                $this->filesMessages[$domain] = $yml;
            }

            // merge
            $this->output->writeln('');
            $this->output->writeln('Merging...');
            $this->mergedMessages = $this->_deepMerge($this->templatesMessages, $this->filesMessages);

            // show files messages
            if($input->getOption('dump-messages') === true){
                $this->output->writeln('');
                $this->output->writeln('Merged messages');
                foreach ($this->mergedMessages as $domain => $messages) {
                    $this->output->writeln('## ' . $domain);
                    $this->_displayArray($messages);
                }
            }
            
            // save the files
            if($input->getOption('force') === true){
                $this->output->writeln('');
                $this->output->writeln('Writing files...');
                $path = $foundBundle->getPath() . '/Resources/translations/';
                foreach ($this->mergedMessages as $domain => $messages) {
                    $file = $domain . '.' . $input->getArgument('locale') . '.yml';
                    $this->output->writeln('Writing ' . $file);
                    $yml = \Symfony\Component\Yaml\Yaml::dump($messages,10);
                    // backup
                    copy($path . $file, $path . '~' . $file);
                    // write
                    file_put_contents($path . $file, $yml);
                }
            }
        }
    }

    /**
     * Recursive function that extract trans message from a twig tree
     * @param \Twig_Node The twig tree root
     */
    private function _crawlNode(\Twig_Node $node) {
        // if trans block
        if ( $node instanceof \Symfony\Bridge\Twig\Node\TransNode &&
            !$node->getNode('body') instanceof \Twig_Node_Expression_GetAttr) {
            // get domain
            $domain = $node->getNode('domain')->getAttribute('value');
            // get message
            $message = $node->getNode('body')->getAttribute('data');
            // save
            $this->_saveMessage($message,$domain);
        }
        // else if trans filter (be carefull of how you chain your filters)
        else if ($node instanceof \Twig_Node_Print) {
            // get message
            $message = $this->_extractMessage($node->getNode('expr'));
            // get domain
            $domain = $this->_extractDomain($node->getNode('expr'));
            // save
            if($message !== null && $domain!== null)
                $this->_saveMessage($message,$domain);
        }
        // if not, continue crawling
        else {
            foreach ($node as $child)
                if ($child != null)
                    $this->_crawlNode($child);
        }
    }
    
    /**
     * Extract a message from a \Twig_Node_Print
     * Return null if not a constant message
     * @param \Twig_Node $node 
     */
    private function _extractMessage(\Twig_Node $node){
        if($node->hasNode('node'))
            return $this->_extractMessage($node->getNode ('node'));
        if($node instanceof \Twig_Node_Expression_Constant)
            return $node->getAttribute('value');
        return null;
    }
    
    /**
     * Extract a domain from a \Twig_Node_Print
     * Return null if no trans filter
     * @param \Twig_Node $node 
     */
    private function _extractDomain(\Twig_Node $node){
        // must be a filter node
        if(!$node instanceof \Twig_Node_Expression_Filter)
            return null;
        // is a trans filter
        if($node->getNode('filter')->getAttribute('value') == 'trans'){
            if($node->getNode('arguments')->hasNode(1))
                return $node->getNode('arguments')->getNode(1)->getAttribute('value');
            else
                return $this->_defaultDomain;
        }
        // try child
        return $this->_extractDomain($node->getNode('node'));
    }
    
    /**
     * Save a message to the templateMessages array
     * @param type $message
     * @param type $domain 
     */
    private function _saveMessage($message, $domain){
        // create the domain
        if (!array_key_exists($domain, $this->templatesMessages)) {
            $this->templatesMessages[$domain] = array();
        }
        // create the message
        if (!array_key_exists($message, $this->templatesMessages[$domain])) {
            $this->templatesMessages[$domain][$message] = $this->_prefix . $message; // add a prefix to the saved message
        }
    }

    /**
     * Recursive function that display a tree-shaped array
     * @param array The array to display
     * @param type The offset to use for display purpose 
     */
    private function _displayArray(array $array, $level = 0) {
        foreach ($array as $key => $value) {
            $this->output->write(str_pad("", $level * 2));
            if (is_array($value)) {
                $this->output->writeln($key . ' :');
                $this->_displayArray($value, $level + 1);
            }
            else
                $this->output->writeln($key . ' : ' . $value);
        }
    }

    /**
     * Merge N arrays recursively, avoid doublons
     * @return type 
     */
    private function _deepMerge() {
        $arrays = func_get_args();
        $base = array_shift($arrays);
        if (!is_array($base))
            $base = empty($base) ? array() : array($base);
        foreach ($arrays as $append) {
            if (!is_array($append))
                $append = array($append);
            foreach ($append as $key => $value) {
                if (!array_key_exists($key, $base) and !is_numeric($key)) {
                    $base[$key] = $append[$key];
                    continue;
                }
                if (is_array($value) or is_array($base[$key])) {
                    $base[$key] = $this->_deepMerge($base[$key], $append[$key]);
                } else if (is_numeric($key)) {
                    if (!in_array($value, $base))
                        $base[] = $value;
                } else {
                    $base[$key] = $value;
                }
            }
        }
        return $base;
    }

}
