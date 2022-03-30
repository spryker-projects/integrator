<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdk\Integrator\Builder\ClassModifier;

use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use SprykerSdk\Integrator\Builder\Checker\ClassMethodCheckerInterface;
use SprykerSdk\Integrator\Builder\Exception\LiteralValueParsingException;
use SprykerSdk\Integrator\Builder\Finder\ClassNodeFinderInterface;
use SprykerSdk\Integrator\Builder\Visitor\AddMethodVisitor;
use SprykerSdk\Integrator\Builder\Visitor\CloneNodeWithClearPositionVisitor;
use SprykerSdk\Integrator\Builder\Visitor\RemoveMethodVisitor;
use SprykerSdk\Integrator\Builder\Visitor\ReplaceNodeStmtByNameVisitor;
use SprykerSdk\Integrator\Transfer\ClassInformationTransfer;

class CommonClassModifier implements CommonClassModifierInterface
{
    /**
     * @var \SprykerSdk\Integrator\Builder\Finder\ClassNodeFinderInterface
     */
    protected $classNodeFinder;

    /**
     * @var \SprykerSdk\Integrator\Builder\Checker\ClassMethodCheckerInterface
     */
    protected $classMethodChecker;

    /**
     * @param \SprykerSdk\Integrator\Builder\Finder\ClassNodeFinderInterface $classNodeFinder
     * @param \SprykerSdk\Integrator\Builder\Checker\ClassMethodCheckerInterface $classMethodChecker
     */
    public function __construct(
        ClassNodeFinderInterface $classNodeFinder,
        ClassMethodCheckerInterface $classMethodChecker
    ) {
        $this->classNodeFinder = $classNodeFinder;
        $this->classMethodChecker = $classMethodChecker;
    }

    /**
     * @param \SprykerSdk\Integrator\Transfer\ClassInformationTransfer $classInformationTransfer
     * @param string $targetMethodName
     *
     * @return \SprykerSdk\Integrator\Transfer\ClassInformationTransfer
     */
    public function overrideMethodFromParent(ClassInformationTransfer $classInformationTransfer, string $targetMethodName): ClassInformationTransfer
    {
        $parentClassType = $classInformationTransfer->getParent();

        if (!$parentClassType) {
            return $classInformationTransfer;
        }

        $methodSyntaxTree = $this->classNodeFinder->findMethodNode($parentClassType, $targetMethodName);

        if (!$methodSyntaxTree) {
            return $classInformationTransfer;
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new CloneNodeWithClearPositionVisitor());

        /** @var \PhpParser\Node\Stmt\ClassMethod $methodSyntaxTree */
        $methodSyntaxTree = $nodeTraverser->traverse([$methodSyntaxTree])[0];

        $methodBody = [];
        if ($this->classMethodChecker->isMethodReturnArray($methodSyntaxTree)) {
            $methodBody = $this->buildMethodBodyToReturnArray($targetMethodName, $methodSyntaxTree);
        } elseif (count($methodSyntaxTree->params) === 1) {
            $methodBody = [new Return_($methodSyntaxTree->params[0]->var)];
        }

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new ReplaceNodeStmtByNameVisitor($targetMethodName, $methodBody));
        $methodSyntaxTree = $nodeTraverser->traverse([$methodSyntaxTree])[0];

        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new AddMethodVisitor($methodSyntaxTree));
        $classInformationTransfer->setClassTokenTree($nodeTraverser->traverse($classInformationTransfer->getClassTokenTree()));

        return $classInformationTransfer;
    }

    /**
     * @param string $targetMethodName
     * @param \PhpParser\Node\Stmt\ClassMethod $methodSyntaxTree
     *
     * @return array<\PhpParser\Node\Stmt\Return_>
     */
    protected function buildMethodBodyToReturnArray(string $targetMethodName, ClassMethod $methodSyntaxTree): array
    {
        $builder = new BuilderFactory();
        $methodBody = [new Return_(new Array_())];
        if ($this->isMethodReturnArrayEmpty($methodSyntaxTree)) {
            return $methodBody;
        }

        return [new Return_(
            $builder->funcCall('array_merge', [
                new Arg(new StaticCall(
                    new Name('parent'),
                    $targetMethodName,
                )),
                new Arg(new Array_()),
            ]),
        )];
    }

    /**
     * @param \SprykerSdk\Integrator\Transfer\ClassInformationTransfer $classInformationTransfer
     * @param string $targetMethodName
     * @param array<\PhpParser\Node> $methodAst
     *
     * @return \SprykerSdk\Integrator\Transfer\ClassInformationTransfer
     */
    public function replaceMethodBody(ClassInformationTransfer $classInformationTransfer, string $targetMethodName, array $methodAst): ClassInformationTransfer
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new ReplaceNodeStmtByNameVisitor($targetMethodName, $methodAst));
        $classInformationTransfer->setClassTokenTree($nodeTraverser->traverse($classInformationTransfer->getClassTokenTree()));

        return $classInformationTransfer;
    }

    /**
     * @param \SprykerSdk\Integrator\Transfer\ClassInformationTransfer $classInformationTransfer
     * @param string $methodNameToRemove
     *
     * @return \SprykerSdk\Integrator\Transfer\ClassInformationTransfer
     */
    public function removeClassMethod(ClassInformationTransfer $classInformationTransfer, string $methodNameToRemove): ClassInformationTransfer
    {
        $nodeTraverser = new NodeTraverser();
        $nodeTraverser->addVisitor(new RemoveMethodVisitor($methodNameToRemove));
        $classInformationTransfer->setClassTokenTree($nodeTraverser->traverse($classInformationTransfer->getClassTokenTree()));

        return $classInformationTransfer;
    }

    /**
     * @param \SprykerSdk\Integrator\Transfer\ClassInformationTransfer $classInformationTransfer
     * @param string $methodName
     * @param array|string|float|int|bool|null $value
     *
     * @return \SprykerSdk\Integrator\Transfer\ClassInformationTransfer
     */
    public function setMethodReturnValue(ClassInformationTransfer $classInformationTransfer, string $methodName, $value): ClassInformationTransfer
    {
        $methodNode = $this->classNodeFinder->findMethodNode($classInformationTransfer, $methodName);
        if (!$methodNode) {
            $classInformationTransfer = $this->overrideMethodFromParent($classInformationTransfer, $methodName);
        }

        $methodBody = [new Return_($this->buildReturnValue($value))];

        return $this->replaceMethodBody($classInformationTransfer, $methodName, $methodBody);
    }

    /**
     * @param mixed $value
     *
     * @throws \SprykerSdk\Integrator\Builder\Exception\LiteralValueParsingException
     *
     * @return \PhpParser\Node\Expr
     */
    protected function buildReturnValue($value): Expr
    {
        if (is_array($value) && isset($value['is_literal'])) {
            $parserFactory = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

            $preparedValue = sprintf('<?php %s;', $value['value']);
            $tree = $parserFactory->parse($preparedValue);

            /** @var \PhpParser\Node\Stmt\Expression|null $expression */
            $expression = $tree[0] ?? null;

            if ($expression === null) {
                throw new LiteralValueParsingException(sprintf('Value is not valid PHP code: `%s`', $value['value']));
            }

            return $expression->expr;
        }

        return (new BuilderFactory())->val($value);
    }

    /**
     * @param \PhpParser\Node\Stmt\ClassMethod $node
     *
     * @return bool
     */
    protected function isMethodReturnArrayEmpty(ClassMethod $node): bool
    {
        /** @var array<\PhpParser\Node> $nodes */
        $nodes = $node->stmts;

        /** @var \PhpParser\Node\Expr\Array_|null $arrayNode */
        $arrayNode = (new NodeFinder())->findFirst($nodes, function (Node $node) {
            return $node instanceof Array_;
        });

        return $arrayNode && !count($arrayNode->items);
    }
}
