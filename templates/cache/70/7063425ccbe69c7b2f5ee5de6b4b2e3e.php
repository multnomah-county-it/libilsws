<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* registration_email.html.twig.en */
class __TwigTemplate_966e20b8024e1e9ba83972eb9ee38848 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        yield "<p>Welcome to Multnomah County Library!</p>
<p>Your temporary library card number is ";
        // line 2
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, ($context["patron"] ?? null), "barcode", [], "any", false, false, false, 2), "html", null, true);
        yield ". With this number and your password, you can instantly access 
<a href=\"https://multcolib.org/e-books-and-more\">e-books and more</a>, streaming movies and music, and other online 
services. You can also place five holds on items you want to check out and pick them up at your library.</p>
<p>Please come to any <a href=\"https://multcolib.org/hours-and-locations\">Multnomah County Library</a> to pick 
up your new library card. Your temporary library card can be used for 6 months.</p>
<p>When you come to the library to get your permanent library card, please bring photo identification or 
<a href=\"https://multcolib.org/contact\">contact us</a> for options. If you are ages 13-17, you can show ID 
or bring an adult with you. If you are under age 13, please bring an adult with you.</p>
<p>Parents, check out <a href=\"https://multcolib.org/parents\">resources for parents</a>, 
<a href=\"https://multcolib.org/educators/homeschooling\">homeschooling resources</a>, 
and the <a href=\"https://multcolib.org/homework-center\">Homework Center</a> for services you and your family 
can use right away.</p>
<p><b>Please <a href=\"https://multcolib.org/contact\">contact us</a> if you have questions about your account, 
password, or using the library online.</b></p>
<p>Thank you for using Multnomah County Library!</p>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "registration_email.html.twig.en";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  45 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "registration_email.html.twig.en", "/Users/johnh3/Documents/php/ilsws/templates/registration_email.html.twig.en");
    }
}
