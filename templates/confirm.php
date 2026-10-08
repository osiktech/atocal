<?php

declare(strict_types=1);

$h = static function (string $value): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$l = $_['l10n'];
?>

<div id="app-content">
    <div class="atocal-container">

        <div class="atocal-card">

            <h1>
                Add To Calendar
            </h1>

            <p class="atocal-intro">
                <?= $h($l->t(
                    'The following meeting will be added to your '
                    . 'Nextcloud Calendar.'
                )) ?>
            </p>

            <p class="atocal-notice">
                <?= $h($l->t(
                    'This request came from an external link. '
                    . 'Only continue if you trust its source.'
                )) ?>
            </p>

            <?php if ($_['error'] !== ''): ?>
                <div class="atocal-error">
                    <?= $h($_['error']) ?>
                </div>
            <?php endif; ?>

            <div class="atocal-details">

                <div class="atocal-row">
                    <strong><?= $h($l->t('Title')) ?></strong>
                    <span><?= $h($_['summary']) ?></span>
                </div>

                <?php if ($_['description'] !== ''): ?>
                    <div class="atocal-row">
                        <strong><?= $h($l->t('Description')) ?></strong>
                        <span class="description">
                            <?= nl2br($h($_['description'])) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="atocal-row">
                    <strong><?= $h($l->t('Start')) ?></strong>
                    <span><?= $h(str_replace('T', ' ', $_['start'])) ?></span>
                </div>

                <div class="atocal-row">
                    <strong><?= $h($l->t('End')) ?></strong>
                    <span><?= $h(str_replace('T', ' ', $_['end'])) ?></span>
                </div>

                <?php if ($_['organizer'] !== ''): ?>
                    <div class="atocal-row">
                        <strong><?= $h($l->t('Organizer')) ?></strong>
                        <span><?= $h($_['organizer']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($_['location'] !== ''): ?>
                    <div class="atocal-row">
                        <strong><?= $h($l->t('Location')) ?></strong>
                        <span>
                            <a
                                href="<?= $h($_['location']) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <?= $h($_['location']) ?>
                            </a>
                        </span>
                    </div>
                <?php endif; ?>

                <?php if ($_['url'] !== '' && $_['url'] !== $_['location']): ?>
                    <div class="atocal-row">
                        <strong><?= $h($l->t('Link')) ?></strong>
                        <span>
                            <a
                                href="<?= $h($_['url']) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <?= $h($_['url']) ?>
                            </a>
                        </span>
                    </div>
                <?php endif; ?>

            </div>

            <form
                method="post"
                action="<?= $h($_['createUrl']) ?>"
            >
                <?php if ($_['calendars'] !== []): ?>
                    <div class="atocal-row">
                        <label for="atocal-calendar">
                            <strong><?= $h($l->t('Calendar')) ?></strong>
                        </label>
                        <select id="atocal-calendar" name="calendarId">
                            <?php foreach ($_['calendars'] as $calendar): ?>
                                <option
                                    value="<?= $h($calendar['id']) ?>"
                                    <?= $calendar['id'] === $_['calendarId'] ? 'selected' : '' ?>
                                >
                                    <?= $h($calendar['displayName']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <input
                    type="hidden"
                    name="requesttoken"
                    value="<?= $h($_['requesttoken']) ?>"
                >

                <input
                    type="hidden"
                    name="summary"
                    value="<?= $h($_['summary']) ?>"
                >

                <input
                    type="hidden"
                    name="description"
                    value="<?= $h($_['description']) ?>"
                >

                <input
                    type="hidden"
                    name="start"
                    value="<?= $h($_['start']) ?>"
                >

                <input
                    type="hidden"
                    name="end"
                    value="<?= $h($_['end']) ?>"
                >

                <input
                    type="hidden"
                    name="organizer"
                    value="<?= $h($_['organizer']) ?>"
                >

                <input
                    type="hidden"
                    name="location"
                    value="<?= $h($_['location']) ?>"
                >

                <input
                    type="hidden"
                    name="url"
                    value="<?= $h($_['url']) ?>"
                >

                <div class="atocal-actions">

                    <button
                        type="submit"
                        class="primary"
                    >
                        <?= $h($l->t('Add to Nextcloud Calendar')) ?>
                    </button>

                    <a
                        href="<?= $h($_['cancelUrl']) ?>"
                        class="secondary"
                    >
                        <?= $h($l->t('Cancel')) ?>
                    </a>

                </div>

            </form>

        </div>

    </div>
</div>