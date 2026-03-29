<?php
declare(strict_types=1);

final class ContentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getSections(): array
    {
        $stmt = $this->pdo->query('SELECT id, section_key, title, content, updated_at FROM site_sections ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    public function getSectionByKey(string $sectionKey): array
    {
        $stmt = $this->pdo->prepare('SELECT id, section_key, title, content, updated_at FROM site_sections WHERE section_key = :section_key LIMIT 1');
        $stmt->execute(['section_key' => $sectionKey]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    public function upsertSection(string $sectionKey, ?string $title, ?string $content): array
    {
        $sql = 'INSERT INTO site_sections (section_key, title, content) VALUES (:section_key, :title, :content)
                ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'section_key' => $sectionKey,
            'title' => $title,
            'content' => $content,
        ]);

        $query = $this->pdo->prepare('SELECT id, section_key, title, content, updated_at FROM site_sections WHERE section_key = :section_key LIMIT 1');
        $query->execute(['section_key' => $sectionKey]);

        $row = $query->fetch();
        return $row === false ? [] : $row;
    }

    public function getMedia(): array
    {
        $stmt = $this->pdo->query('SELECT id, media_type, title, file_path, alt_text, sort_order, is_active, created_at, updated_at FROM media_assets ORDER BY sort_order ASC, id DESC');
        return $stmt->fetchAll();
    }

    public function createMedia(array $data): array
    {
        $sql = 'INSERT INTO media_assets (media_type, title, file_path, alt_text, sort_order, is_active)
                VALUES (:media_type, :title, :file_path, :alt_text, :sort_order, :is_active)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    alt_text = VALUES(alt_text),
                    sort_order = VALUES(sort_order),
                    is_active = VALUES(is_active),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'media_type' => $data['media_type'],
            'title' => $data['title'],
            'file_path' => $data['file_path'],
            'alt_text' => $data['alt_text'],
            'sort_order' => $data['sort_order'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findMediaByNaturalKey($data['media_type'], $data['file_path']);
    }

    public function updateMedia(int $id, array $data): array
    {
        $sql = 'UPDATE media_assets
                SET media_type = :media_type,
                    title = :title,
                    file_path = :file_path,
                    alt_text = :alt_text,
                    sort_order = :sort_order,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'media_type' => $data['media_type'],
            'title' => $data['title'],
            'file_path' => $data['file_path'],
            'alt_text' => $data['alt_text'],
            'sort_order' => $data['sort_order'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findMediaById($id);
    }

    public function deleteMedia(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM media_assets WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function getLinks(): array
    {
        $stmt = $this->pdo->query('SELECT id, platform, label, url, is_active, sort_order, created_at, updated_at FROM external_links ORDER BY sort_order ASC, id DESC');
        return $stmt->fetchAll();
    }

    public function createLink(array $data): array
    {
        $sql = 'INSERT INTO external_links (platform, label, url, is_active, sort_order)
                VALUES (:platform, :label, :url, :is_active, :sort_order)
                ON DUPLICATE KEY UPDATE
                    label = VALUES(label),
                    is_active = VALUES(is_active),
                    sort_order = VALUES(sort_order),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'platform' => $data['platform'],
            'label' => $data['label'],
            'url' => $data['url'],
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'],
        ]);

        return $this->findLinkByNaturalKey($data['platform'], $data['url']);
    }

    public function updateLink(int $id, array $data): array
    {
        $sql = 'UPDATE external_links
                SET platform = :platform,
                    label = :label,
                    url = :url,
                    is_active = :is_active,
                    sort_order = :sort_order,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'platform' => $data['platform'],
            'label' => $data['label'],
            'url' => $data['url'],
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'],
        ]);

        return $this->findLinkById($id);
    }

    public function deleteLink(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM external_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function getStoreInfo(): array
    {
        $stmt = $this->pdo->query('SELECT id, business_hours, updated_at FROM store_info WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    public function upsertStoreInfo(array $data): array
    {
        $sql = 'INSERT INTO store_info (id, business_hours)
                VALUES (1, :business_hours)
                ON DUPLICATE KEY UPDATE
                    business_hours = VALUES(business_hours),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'business_hours' => $data['business_hours'] ?? null,
        ]);

        return $this->getStoreInfo();
    }

    public function getPublicNews(int $limit = 4): array
    {
        $safeLimit = max(1, min($limit, 50));
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url AS imageUrl, published_at AS publishedAt, link
             FROM news_posts
             WHERE is_active = 1
             ORDER BY published_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getPublicArticles(int $limit = 4): array
    {
        $safeLimit = max(1, min($limit, 50));
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url AS imageUrl, published_at AS publishedAt, link
             FROM article_posts
             WHERE is_active = 1
             ORDER BY published_at DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $safeLimit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getAllNews(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM news_posts
             ORDER BY published_at DESC, id DESC'
        );

        return $stmt->fetchAll();
    }

    public function getAllArticles(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM article_posts
             ORDER BY published_at DESC, id DESC'
        );

        return $stmt->fetchAll();
    }

    public function createNews(array $data): array
    {
        $sql = 'INSERT INTO news_posts (title, excerpt, image_url, published_at, link, is_active)
                VALUES (:title, :excerpt, :image_url, :published_at, :link, :is_active)
                ON DUPLICATE KEY UPDATE
                    excerpt = VALUES(excerpt),
                    image_url = VALUES(image_url),
                    link = VALUES(link),
                    is_active = VALUES(is_active),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'image_url' => $data['image_url'],
            'published_at' => $data['published_at'],
            'link' => $data['link'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findNewsByNaturalKey($data['title'], $data['published_at']);
    }

    public function updateNews(int $id, array $data): array
    {
        $sql = 'UPDATE news_posts
                SET title = :title,
                    excerpt = :excerpt,
                    image_url = :image_url,
                    published_at = :published_at,
                    link = :link,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'image_url' => $data['image_url'],
            'published_at' => $data['published_at'],
            'link' => $data['link'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findNewsById($id);
    }

    public function deleteNews(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM news_posts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function createArticle(array $data): array
    {
        $sql = 'INSERT INTO article_posts (title, excerpt, image_url, published_at, link, is_active)
                VALUES (:title, :excerpt, :image_url, :published_at, :link, :is_active)
                ON DUPLICATE KEY UPDATE
                    excerpt = VALUES(excerpt),
                    image_url = VALUES(image_url),
                    link = VALUES(link),
                    is_active = VALUES(is_active),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'image_url' => $data['image_url'],
            'published_at' => $data['published_at'],
            'link' => $data['link'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findArticleByNaturalKey($data['title'], $data['published_at']);
    }

    public function updateArticle(int $id, array $data): array
    {
        $sql = 'UPDATE article_posts
                SET title = :title,
                    excerpt = :excerpt,
                    image_url = :image_url,
                    published_at = :published_at,
                    link = :link,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'title' => $data['title'],
            'excerpt' => $data['excerpt'],
            'image_url' => $data['image_url'],
            'published_at' => $data['published_at'],
            'link' => $data['link'],
            'is_active' => $data['is_active'],
        ]);

        return $this->findArticleById($id);
    }

    public function deleteArticle(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM article_posts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function getArticleById(int $id): array
    {
        return $this->findArticleById($id);
    }

    public function getNewsById(int $id): array
    {
        return $this->findNewsById($id);
    }

    public function isImagePathInUse(string $imagePath, ?int $excludeNewsId = null): bool
    {
        if ($imagePath === '') {
            return false;
        }

        if ($excludeNewsId === null) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM news_posts WHERE image_url = :image_url');
            $stmt->execute(['image_url' => $imagePath]);
        } else {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM news_posts WHERE image_url = :image_url AND id != :exclude_id');
            $stmt->execute([
                'image_url' => $imagePath,
                'exclude_id' => $excludeNewsId,
            ]);
        }

        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM media_assets WHERE file_path = :image_path');
        $stmt->execute(['image_path' => $imagePath]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM article_posts WHERE image_url = :image_path');
        $stmt->execute(['image_path' => $imagePath]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM senior_care_content WHERE image_url = :image_path OR image_url_2 = :image_path OR image_url_3 = :image_path OR image_url_4 = :image_path');
        $stmt->execute(['image_path' => $imagePath]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function getSeniorCareContent(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, title, subtitle, description, tags, image_url AS imageUrl, image_url_2 AS imageUrl2, image_url_3 AS imageUrl3, image_url_4 AS imageUrl4, updated_at
             FROM senior_care_content
             WHERE id = 1
             LIMIT 1'
        );
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    public function upsertSeniorCareContent(array $data): array
    {
        $sql = 'INSERT INTO senior_care_content (id, title, subtitle, description, tags, image_url, image_url_2, image_url_3, image_url_4)
                VALUES (1, :title, :subtitle, :description, :tags, :image_url, :image_url_2, :image_url_3, :image_url_4)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    subtitle = VALUES(subtitle),
                    description = VALUES(description),
                    tags = VALUES(tags),
                    image_url = VALUES(image_url),
                    image_url_2 = VALUES(image_url_2),
                    image_url_3 = VALUES(image_url_3),
                    image_url_4 = VALUES(image_url_4),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'title' => $data['title'],
            'subtitle' => $data['subtitle'],
            'description' => $data['description'],
            'tags' => $data['tags'],
            'image_url' => $data['image_url'],
            'image_url_2' => $data['image_url_2'] ?? '',
            'image_url_3' => $data['image_url_3'] ?? '',
            'image_url_4' => $data['image_url_4'] ?? '',
        ]);

        return $this->getSeniorCareContent();
    }

    public function getAboutContent(): array
    {
        $stmt = $this->pdo->query('SELECT id, title, content, updated_at FROM about_content WHERE id = 1 LIMIT 1');
        $row = $stmt->fetch();
        if ($row === false) {
            return ['id' => 1, 'title' => '', 'content' => '', 'updated_at' => null];
        }

        return $row;
    }

    public function upsertAboutContent(array $data): array
    {
        $sql = 'INSERT INTO about_content (id, title, content)
                VALUES (1, :title, :content)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    content = VALUES(content),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'title' => $data['title'],
            'content' => $data['content'],
        ]);

        return $this->getAboutContent();
    }

    public function getAboutModals(): array
    {
        $stmt = $this->pdo->query('SELECT id, modal_key, title, content, image_url, updated_at FROM about_modals ORDER BY id ASC');
        return $stmt->fetchAll();
    }

    public function upsertAboutModal(string $modalKey, array $data): array
    {
        $sql = 'INSERT INTO about_modals (modal_key, title, content, image_url)
                VALUES (:modal_key, :title, :content, :image_url)
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    content = VALUES(content),
                    image_url = VALUES(image_url),
                    updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'modal_key' => $modalKey,
            'title' => $data['title'],
            'content' => $data['content'],
            'image_url' => $data['image_url'],
        ]);

        return $this->findAboutModalByKey($modalKey);
    }

    private function findMediaById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, media_type, title, file_path, alt_text, sort_order, is_active, created_at, updated_at FROM media_assets WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    private function findLinkById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT id, platform, label, url, is_active, sort_order, created_at, updated_at FROM external_links WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    private function findMediaByNaturalKey(string $mediaType, string $filePath): array
    {
        $stmt = $this->pdo->prepare('SELECT id, media_type, title, file_path, alt_text, sort_order, is_active, created_at, updated_at FROM media_assets WHERE media_type = :media_type AND file_path = :file_path LIMIT 1');
        $stmt->execute([
            'media_type' => $mediaType,
            'file_path' => $filePath,
        ]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    private function findLinkByNaturalKey(string $platform, string $url): array
    {
        $stmt = $this->pdo->prepare('SELECT id, platform, label, url, is_active, sort_order, created_at, updated_at FROM external_links WHERE platform = :platform AND url = :url LIMIT 1');
        $stmt->execute([
            'platform' => $platform,
            'url' => $url,
        ]);
        $row = $stmt->fetch();
        return $row === false ? [] : $row;
    }

    private function findNewsById(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM news_posts
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    private function findNewsByNaturalKey(string $title, string $publishedAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM news_posts
             WHERE title = :title AND published_at = :published_at
             LIMIT 1'
        );
        $stmt->execute([
            'title' => $title,
            'published_at' => $publishedAt,
        ]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    private function findArticleById(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM article_posts
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    private function findArticleByNaturalKey(string $title, string $publishedAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, title, excerpt, image_url, published_at, link, is_active, created_at, updated_at
             FROM article_posts
             WHERE title = :title AND published_at = :published_at
             LIMIT 1'
        );
        $stmt->execute([
            'title' => $title,
            'published_at' => $publishedAt,
        ]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }

    private function findAboutModalByKey(string $modalKey): array
    {
        $stmt = $this->pdo->prepare('SELECT id, modal_key, title, content, image_url, updated_at FROM about_modals WHERE modal_key = :modal_key LIMIT 1');
        $stmt->execute(['modal_key' => $modalKey]);
        $row = $stmt->fetch();

        return $row === false ? [] : $row;
    }
}
