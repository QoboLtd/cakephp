<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         5.3.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\ORM;

use Cake\Core\Configure;
use Cake\ORM\EagerLoader;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * Tests joining the same association through different paths in a single
 * query, which is enabled with the `Database.deepAssociations` option.
 *
 * The tables are wired so that `Profiles` is reachable through two paths:
 *
 * - Comments -> Users -> Profiles
 * - Comments -> Articles -> Authors -> Profiles
 */
class DeepAssociationsTest extends TestCase
{
    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'core.Articles',
        'core.ArticlesTags',
        'core.Authors',
        'core.Comments',
        'core.Profiles',
        'core.Tags',
        'core.Users',
    ];

    protected Table $comments;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('Database.deepAssociations', true);

        $this->comments = $this->getTableLocator()->get('Comments');
        $this->comments->belongsTo('Articles');
        $this->comments->belongsTo('Users');

        $articles = $this->comments->Articles->getTarget();
        $articles->belongsTo('Authors');
        $articles->belongsToMany('Tags');

        $authors = $articles->Authors->getTarget();
        $authors->hasMany('Articles');
        $authors->hasOne('Profiles', ['foreignKey' => 'user_id']);

        $this->comments->Users->getTarget()->hasOne('Profiles', ['foreignKey' => 'user_id']);
    }

    /**
     * Strips identifier quoting so the SQL can be asserted regardless of the driver.
     */
    protected function sql(SelectQuery $query): string
    {
        return preg_replace('/[`"\[\]]/', '', $query->sql());
    }

    public function testConfigureDefault(): void
    {
        $loader = new EagerLoader();
        $this->assertTrue($loader->isDeepAssociationsEnabled());

        Configure::write('Database.deepAssociations', false);
        $loader = new EagerLoader();
        $this->assertFalse($loader->isDeepAssociationsEnabled());

        $this->assertSame($loader, $loader->setDeepAssociations(true));
        $this->assertTrue($loader->isDeepAssociationsEnabled());
    }

    public function testDisabledKeepsAssociationNameAsAlias(): void
    {
        Configure::write('Database.deepAssociations', false);

        $query = $this->comments->find()->contain(['Users.Profiles']);
        $sql = $this->sql($query);

        $this->assertStringContainsString('LEFT JOIN profiles Profiles ON Users.id = Profiles.user_id', $sql);
        $this->assertStringNotContainsString('Users_Profiles', $sql);
    }

    public function testNestedJoinsUsePathAliases(): void
    {
        $query = $this->comments->find()->contain(['Users.Profiles', 'Articles.Authors.Profiles']);
        $sql = $this->sql($query);

        $this->assertStringContainsString(
            'LEFT JOIN profiles Users_Profiles ON Users.id = Users_Profiles.user_id',
            $sql,
        );
        $this->assertStringContainsString(
            'LEFT JOIN authors Articles_Authors ON Articles_Authors.id = Articles.author_id',
            $sql,
        );
        $this->assertStringContainsString(
            'LEFT JOIN profiles Articles_Authors_Profiles ON Articles_Authors.id = Articles_Authors_Profiles.user_id',
            $sql,
        );
        $this->assertStringContainsString('Users_Profiles.first_name AS Users_Profiles__first_name', $sql);
        $this->assertStringContainsString(
            'Articles_Authors_Profiles.first_name AS Articles_Authors_Profiles__first_name',
            $sql,
        );
        // Top level associations keep their name as alias.
        $this->assertStringContainsString('LEFT JOIN users Users ON Users.id = Comments.user_id', $sql);
        $this->assertStringContainsString('LEFT JOIN articles Articles ON Articles.id = Comments.article_id', $sql);
    }

    public function testContainSameAssociationThroughDifferentPaths(): void
    {
        $comment = $this->comments->find()
            ->contain(['Users.Profiles', 'Articles.Authors.Profiles'])
            ->where(['Comments.id' => 1])
            ->firstOrFail();

        $this->assertSame(2, $comment->user->id);
        $this->assertSame('nate', $comment->user->profile->first_name);
        $this->assertSame(1, $comment->article->author->id);
        $this->assertSame('mariano', $comment->article->author->profile->first_name);
        $this->assertSame('Profiles', $comment->user->profile->getSource());
        $this->assertSame('Profiles', $comment->article->author->profile->getSource());

        $comment = $this->comments->find()
            ->contain(['Users.Profiles', 'Articles.Authors.Profiles'])
            ->where(['Comments.id' => 1])
            ->disableHydration()
            ->firstOrFail();

        $this->assertSame('nate', $comment['user']['profile']['first_name']);
        $this->assertSame('mariano', $comment['article']['author']['profile']['first_name']);
        $this->assertArrayNotHasKey('Users_Profiles', $comment);
        $this->assertArrayNotHasKey('Articles_Authors_Profiles', $comment);
    }

    public function testConditionsOnPathAlias(): void
    {
        $results = $this->comments->find()
            ->contain(['Users.Profiles', 'Articles.Authors.Profiles'])
            ->where([
                'Users_Profiles.first_name' => 'nate',
                'Articles_Authors_Profiles.first_name' => 'mariano',
            ])
            ->orderBy(['Comments.id' => 'ASC'])
            ->all()
            ->extract('id')
            ->toList();

        $this->assertSame([1], $results);
    }

    public function testContainQueryBuilderReferencesTargetAlias(): void
    {
        $query = $this->comments->find()
            ->contain([
                'Users.Profiles' => function (SelectQuery $q) {
                    return $q
                        ->select(['Profiles.first_name', 'Profiles.is_active'])
                        ->where(['Profiles.is_active' => false]);
                },
            ])
            ->where(['Comments.id' => 1]);
        $sql = $this->sql($query);

        $this->assertStringContainsString('Users_Profiles.first_name AS Users_Profiles__first_name', $sql);
        $this->assertStringContainsString('Users_Profiles.is_active = :c', $sql);
        $this->assertStringNotContainsString(' Profiles.', $sql);

        $comment = $query->firstOrFail();
        $this->assertSame('nate', $comment->user->profile->first_name);
        $this->assertFalse($comment->user->profile->is_active);
        $this->assertNull($comment->user->profile->last_name);
    }

    public function testAssociationConditionsReferencingTargetAlias(): void
    {
        $users = $this->comments->Users->getTarget();
        $users->associations()->remove('Profiles');
        $users->hasOne('Profiles', [
            'foreignKey' => 'user_id',
            'conditions' => ['Profiles.is_active' => true],
        ]);

        $query = $this->comments->find()
            ->contain(['Users.Profiles'])
            ->where(['Comments.id' => 1]);
        $sql = $this->sql($query);

        $this->assertStringContainsString('Users_Profiles.is_active = :c', $sql);

        $comment = $query->firstOrFail();
        $this->assertNull($comment->user->profile);
    }

    public function testBeforeFindConditionsAreRewritten(): void
    {
        $profiles = $this->getTableLocator()->get('Profiles');
        $profiles->getEventManager()->on('Model.beforeFind', function ($event, SelectQuery $query): void {
            $query->where(['Profiles.first_name !=' => 'nate']);
        });

        $query = $this->comments->find()
            ->contain(['Users.Profiles'])
            ->where(['Comments.id' => 1]);

        $this->assertStringContainsString('Users_Profiles.first_name != :c', $this->sql($query));
        $this->assertNull($query->firstOrFail()->user->profile);
    }

    public function testExternalAssociationBelowPathAlias(): void
    {
        // Authors hasMany Articles is loaded with a separate query, using the keys
        // selected from the `Articles_Authors` join.
        $comment = $this->comments->find()
            ->contain(['Articles.Authors.Articles'])
            ->where(['Comments.id' => 1])
            ->firstOrFail();

        $this->assertSame(1, $comment->article->author->id);
        $articles = array_map(fn($article) => $article->id, $comment->article->author->articles);
        $this->assertSame([1, 3], $articles);

        $comment = $this->comments->find()
            ->contain(['Articles.Authors.Articles'])
            ->where(['Comments.id' => 1])
            ->disableHydration()
            ->firstOrFail();
        $this->assertCount(2, $comment['article']['author']['articles']);
    }

    public function testExternalAssociationBelowPathAliasWithMissingParent(): void
    {
        $comments = $this->comments;
        $comment = $comments->newEntity(['article_id' => 999, 'user_id' => 1, 'comment' => 'orphan']);
        $comments->saveOrFail($comment);

        $result = $comments->find()
            ->contain(['Articles.Authors.Articles'])
            ->where(['Comments.id' => $comment->id])
            ->firstOrFail();

        $this->assertNull($result->article);
    }

    public function testMatchingSameAssociationThroughDifferentPaths(): void
    {
        $query = $this->comments->find()
            ->matching('Users.Profiles')
            ->matching('Articles.Authors.Profiles')
            ->where(['Comments.id' => 1]);
        $sql = $this->sql($query);

        $this->assertStringContainsString(
            'INNER JOIN profiles Users_Profiles ON Users.id = Users_Profiles.user_id',
            $sql,
        );
        $this->assertStringContainsString(
            'INNER JOIN profiles Articles_Authors_Profiles ON ' .
            'Articles_Authors.id = Articles_Authors_Profiles.user_id',
            $sql,
        );

        $comment = $query->firstOrFail();
        $matching = $comment->_matchingData;
        $this->assertSame(
            ['Users', 'Users_Profiles', 'Articles', 'Articles_Authors', 'Articles_Authors_Profiles'],
            array_keys($matching),
        );
        $this->assertSame('nate', $matching['Users_Profiles']->first_name);
        $this->assertSame('mariano', $matching['Articles_Authors_Profiles']->first_name);
        $this->assertSame('Profiles', $matching['Users_Profiles']->getSource());
    }

    public function testMatchingWithConditionsOnTargetAlias(): void
    {
        $ids = $this->comments->find()
            ->matching('Users.Profiles', function (SelectQuery $q) {
                return $q->where(['Profiles.first_name' => 'nate']);
            })
            ->orderBy(['Comments.id' => 'ASC'])
            ->all()
            ->extract('id')
            ->toList();

        $this->assertSame([1, 6], $ids);

        $ids = $this->comments->find()
            ->leftJoinWith('Users.Profiles')
            ->where(['Users_Profiles.first_name' => 'garrett'])
            ->all()
            ->extract('id')
            ->toList();

        $this->assertSame([2], $ids);
    }

    public function testNotMatchingNested(): void
    {
        $query = $this->comments->find()
            ->notMatching('Users.Profiles', function (SelectQuery $q) {
                return $q->where(['Profiles.first_name' => 'nate']);
            })
            ->orderBy(['Comments.id' => 'ASC']);

        $this->assertStringContainsString('(Users_Profiles.id) IS NULL', $this->sql($query));
        $this->assertSame([2, 3, 4, 5], $query->all()->extract('id')->toList());
    }

    public function testMatchingNestedBelongsToMany(): void
    {
        $query = $this->comments->find()
            ->matching('Articles.Tags', function (SelectQuery $q) {
                return $q->where(['Tags.id' => 2]);
            })
            ->orderBy(['Comments.id' => 'ASC']);
        $sql = $this->sql($query);

        $this->assertStringContainsString(
            'INNER JOIN articles_tags Articles_ArticlesTags ON Articles.id = Articles_ArticlesTags.article_id',
            $sql,
        );
        $this->assertStringContainsString(
            'INNER JOIN tags Articles_Tags ON (Articles_Tags.id = :c0 AND Articles_Tags.id = Articles_ArticlesTags.tag_id)',
            $sql,
        );

        $results = $query->all();
        $this->assertSame([1, 2, 3, 4], $results->extract('id')->toList());

        $matching = $results->first()->_matchingData;
        $keys = array_keys($matching);
        sort($keys);
        $this->assertSame(['Articles', 'Articles_ArticlesTags', 'Articles_Tags'], $keys);
        $this->assertSame('tag2', $matching['Articles_Tags']->name);
        $this->assertSame(2, $matching['Articles_ArticlesTags']->tag_id);
    }

    public function testNotMatchingNestedBelongsToMany(): void
    {
        $ids = $this->comments->find()
            ->notMatching('Articles.Tags', function (SelectQuery $q) {
                return $q->where(['Tags.id' => 2]);
            })
            ->orderBy(['Comments.id' => 'ASC'])
            ->all()
            ->extract('id')
            ->toList();

        $this->assertSame([5, 6], $ids);
    }

    public function testJoinWithConflictingAliasesResolved(): void
    {
        $comments = $this->getTableLocator()->get('Comments');
        $comments->belongsTo('Authors', [
            'className' => 'Authors',
            'foreignKey' => 'user_id',
        ]);

        $query = $comments->find()
            ->leftJoinWith('Authors')
            ->leftJoinWith('Articles', fn(SelectQuery $q) => $q->leftJoinWith('Authors'))
            ->where(['Comments.id' => 1]);

        $this->assertStringContainsString('LEFT JOIN authors Articles_Authors', $this->sql($query));

        $result = $query
            ->contain(['Authors', 'Articles.Authors'])
            ->firstOrFail();

        $this->assertSame(2, $result->author->id);
        $this->assertSame(1, $result->article->author->id);
    }

    public function testMatchingLoaderInheritsSetting(): void
    {
        Configure::write('Database.deepAssociations', false);

        $query = $this->comments->find();
        $query->getEagerLoader()->setDeepAssociations(true);
        $query->matching('Users.Profiles');

        $this->assertStringContainsString('profiles Users_Profiles', $this->sql($query));

        $query = $this->comments->find()->matching('Users.Profiles');
        $query->getEagerLoader()->setDeepAssociations(true);

        $this->assertStringContainsString('profiles Users_Profiles', $this->sql($query));
    }

    public function testDuplicateContainNoLongerDowngradesStrategy(): void
    {
        $articles = $this->getTableLocator()->get('Articles');
        $articles->belongsTo('Creator', ['className' => 'Authors', 'foreignKey' => 'author_id']);
        $articles->belongsTo('Modifier', ['className' => 'Authors', 'foreignKey' => 'author_id']);
        $articles->Creator->getTarget()->hasOne('Profiles', ['foreignKey' => 'user_id']);
        $articles->Modifier->getTarget()->hasOne('Profiles', ['foreignKey' => 'user_id']);

        $query = $articles->find()
            ->contain(['Creator.Profiles', 'Modifier.Profiles'])
            ->where(['Articles.id' => 2]);
        $sql = $this->sql($query);

        $this->assertStringContainsString('LEFT JOIN profiles Creator_Profiles', $sql);
        $this->assertStringContainsString('LEFT JOIN profiles Modifier_Profiles', $sql);
        $this->assertSame([], $query->getEagerLoader()->externalAssociations($articles));

        $article = $query->firstOrFail();
        $this->assertSame('larry', $article->creator->profile->first_name);
        $this->assertSame('larry', $article->modifier->profile->first_name);
    }
}
