<?php
declare(strict_types=1);

namespace OCA\Opsdash\Service;

final class OverviewCorePayloadComposer {
    /**
     * @param array{
     *   includeAll: bool,
     *   includes: array<string,bool>,
     *   userSettings: array<string, mixed>,
     *   calendars: array<int, array<string, mixed>>,
     *   selected: array<int, string>,
     *   colorsById: array<string, string>,
     *   colorsByName: array<string, string>,
     *   groupsById: array<string, int>,
     *   targetsWeek: array<string, mixed>,
     *   targetsMonth: array<string, mixed>,
     *   targetsConfig: array<string, mixed>,
     *   themePreference: string,
     *   reportingConfig: array<string, mixed>,
     *   deckSettings: array<string, mixed>,
     *   widgets: array<int, mixed>,
     *   widgetPresets: array<string, mixed>,
     *   onboarding: array<string, mixed>
     * } $context
     * @return array<string, mixed>
     */
    public function compose(array $context): array {
        $includeAll = (bool)$context['includeAll'];
        $includes = $context['includes'];
        $shouldInclude = fn(string $key): bool => $includeAll || isset($includes[$key]);

        $payload = [];
        if ($shouldInclude('userSettings') || isset($includes['core'])) {
            $payload['userSettings'] = $context['userSettings'];
        }
        if ($shouldInclude('calendars') || isset($includes['core'])) {
            $payload['calendars'] = $context['calendars'];
        }
        if ($shouldInclude('selected') || isset($includes['core'])) {
            $payload['selected'] = $context['selected'];
        }
        if ($shouldInclude('colors') || isset($includes['core'])) {
            $payload['colors'] = ['byId' => $context['colorsById'], 'byName' => $context['colorsByName']];
        }
        if ($shouldInclude('groups') || isset($includes['core'])) {
            $payload['groups'] = ['byId' => $context['groupsById']];
        }
        if ($shouldInclude('targets') || isset($includes['core'])) {
            $payload['targets'] = ['week' => $context['targetsWeek'], 'month' => $context['targetsMonth']];
        }
        if ($shouldInclude('targetsConfig') || isset($includes['core'])) {
            $payload['targetsConfig'] = $context['targetsConfig'];
        }
        if ($shouldInclude('themePreference') || isset($includes['core'])) {
            $payload['themePreference'] = $context['themePreference'];
        }
        if ($shouldInclude('reportingConfig') || isset($includes['core'])) {
            $payload['reportingConfig'] = $context['reportingConfig'];
        }
        if ($shouldInclude('deckSettings') || isset($includes['core'])) {
            $payload['deckSettings'] = $context['deckSettings'];
        }
        if ($shouldInclude('widgets') || isset($includes['core'])) {
            $payload['widgets'] = $context['widgets'];
            if (!empty($context['widgetPresets'])) {
                $payload['widgetPresets'] = $context['widgetPresets'];
            }
        }
        if ($shouldInclude('onboarding') || isset($includes['core'])) {
            $payload['onboarding'] = $context['onboarding'];
        }
        if (isset($context['activePreset']) && $context['activePreset'] !== '') {
            $payload['activePreset'] = $context['activePreset'];
        }
        if (!empty($context['preferredScope'])) {
            $payload['preferredScope'] = $context['preferredScope'];
        }
        if (!empty($context['globalAppBg'])) {
            $payload['globalAppBg'] = $context['globalAppBg'];
        }
        return $payload;
    }
}
