<?php
/**
 * REST API for the Dalfred module.
 *
 * Endpoints registered automatically by Dolibarr because the file follows
 * the api_<module>.class.php convention.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT . '/main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/api/class/api.class.php';
require_once dol_buildpath('/dalfred/src/Service/GeneratedFilesService.php');

/**
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Dalfred extends DolibarrApi
{
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    private function ensureToolkitEnabled(): void
    {
        if (\getDolGlobalString('DALFRED_FILE_GEN_ENABLED') !== '1') {
            throw new RestException(403, 'FileGenerationDisabled');
        }
    }

    private function service(): \Dalfred\Service\GeneratedFilesService
    {
        $max = (int) \getDolGlobalInt('DALFRED_FILE_GEN_MAX_SIZE', 5242880);
        // dol_buildpath('/dalfred/download.php', 1) resolves both the install sub-path
        // (DOL_URL_ROOT, e.g. '/erp') AND the actual case of the custom dir
        // (e.g. 'Custom/' on hosts like customer-instance). Must be in the download_url
        // so the Markdown link the agent emits resolves correctly in the client browser.
        return new \Dalfred\Service\GeneratedFilesService(DOL_DATA_ROOT, $max, dol_buildpath('/dalfred/download.php', 1));
    }

    private function currentUserId(): int
    {
        $uid = (int) (DolibarrApiAccess::$user->id ?? 0);
        if ($uid <= 0) {
            throw new RestException(401, 'NotAuthenticated');
        }
        return $uid;
    }

    /**
     * Create a file for the current user.
     *
     * @url POST /generated_files/create
     *
     * @param array $request_data Request body: {filename:string, format:string, content:string}
     *
     * @return array
     * @throws RestException 400, 403, 500
     */
    public function createGeneratedFile($request_data = null): array
    {
        $this->ensureToolkitEnabled();
        $uid = $this->currentUserId();

        if ($request_data !== null && !is_array($request_data)) {
            throw new RestException(400, 'MissingRequiredFields');
        }
        $request_data = $request_data ?? [];

        $filename = (string) ($request_data['filename'] ?? '');
        $format   = (string) ($request_data['format'] ?? '');
        $content  = (string) ($request_data['content'] ?? '');

        if ($filename === '' || $format === '') {
            throw new RestException(400, 'MissingRequiredFields');
        }

        try {
            $result = $this->service()->create($uid, $filename, $format, $content);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            $status = match ($code) {
                'FileGenerationDisabled' => 403,
                'InvalidFormat', 'InvalidFilename', 'FileTooLarge' => 400,
                default => 500,
            };
            throw new RestException($status, $code);
        }

        return ['success' => true] + $result;
    }

    /**
     * List generated files of the current user.
     *
     * @url GET /generated_files/list
     *
     * @return array{success:bool, files:array}
     */
    public function listGeneratedFiles(): array
    {
        $this->ensureToolkitEnabled();
        $uid = $this->currentUserId();
        return ['success' => true, 'files' => $this->service()->listForUser($uid)];
    }

    /**
     * Delete one generated file of the current user.
     *
     * @url DELETE /generated_files/{filename}
     *
     * @param string $filename
     * @return array{success:bool}
     * @throws RestException 400, 403, 404
     */
    public function deleteGeneratedFile(string $filename): array
    {
        $this->ensureToolkitEnabled();
        $uid = $this->currentUserId();
        try {
            $this->service()->delete($uid, $filename);
        } catch (\RuntimeException $e) {
            $code = $e->getMessage();
            $status = match ($code) {
                'NotFound'        => 404,
                'InvalidFilename' => 400,
                default           => 500,
            };
            throw new RestException($status, $code);
        }
        return ['success' => true];
    }
}
