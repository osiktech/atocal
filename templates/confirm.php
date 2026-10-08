<?php

declare(strict_types=1);

$h = static function (string $value): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};
?>

<div id="app-content">
    <div class="atocal-container">

        <div class="atocal-card">

            <h1>
                Add To Calendar
            </h1>

            <p class="atocal-intro">
                The following meeting will be added to your
                Nextcloud Calendar.
            </p>

            <?php if ($_['error'] !== ''): ?>
                <div class="atocal-error">
                    <?= $h($_['error']) ?>
                </div>
            <?php endif; ?>

            <div class="atocal-details">

                <div class="atocal-row">
                    <strong>Title</strong>
                    <span><?= $h($_['summary']) ?></span>
                </div>

                <?php if ($_['description'] !== ''): ?>
                    <div class="atocal-row">
                        <strong>Description</strong>
                        <span class="description">
                            <?= nl2br($h($_['description'])) ?>
                        </span>
                    </div>
                <?php endif; ?>

                <div class="atocal-row">
                    <strong>Start</strong>
                    <span><?= $h(str_replace('T', ' ', $_['start'])) ?></span>
                </div>

                <div class="atocal-row">
                    <strong>End</strong>
                    <span><?= $h(str_replace('T', ' ', $_['end'])) ?></span>
                </div>

                <?php if ($_['organizer'] !== ''): ?>
                    <div class="atocal-row">
                        <strong>Organizer</strong>
                        <span><?= $h($_['organizer']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($_['location'] !== ''): ?>
                    <div class="atocal-row">
                        <strong>Location</strong>
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

            </div>

            <form
                method="post"
                action="<?= $h($_['createUrl']) ?>"
            >
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
                        Add to Nextcloud Calendar
                    </button>

                    <a
                        href="https://cloud.bnvb-ingenieure.de/"
                        class="secondary"
                    >
                        Cancel
                    </a>

                </div>

            </form>

        </div>

    </div>
</div>