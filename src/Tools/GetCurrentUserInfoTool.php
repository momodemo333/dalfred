<?php

declare(strict_types=1);

namespace Dalfred\Tools;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class GetCurrentUserInfoTool extends Tool
{
    protected \DoliDB $db;
    protected int $userId;
    protected int $entityId;

    public function __construct(\DoliDB $db, int $userId, int $entityId = 1)
    {
        parent::__construct(
            name: 'get_current_user_info',
            description: 'Obtenir les informations détaillées de l\'utilisateur courant : groupes, permissions actives, champs supplémentaires (extrafields), et tiers lié. Utilise cet outil quand l\'utilisateur pose des questions sur son profil, ses droits ou ses groupes.',
        );

        $this->db = $db;
        $this->userId = $userId;
        $this->entityId = $entityId;
    }

    public function properties(): array
    {
        return [];
    }

    public function __invoke(): string
    {
        $result = [];

        // Groups
        $result['groups'] = $this->getUserGroups();

        // Key permissions (module-level summary)
        $result['permissions'] = $this->getUserPermissions();

        // Extrafields
        $result['extrafields'] = $this->getUserExtrafields();

        // Linked thirdparty
        $result['linked_thirdparty'] = $this->getLinkedThirdparty();

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return json_encode(['error' => true, 'message' => 'Failed to encode user info: ' . json_last_error_msg()]);
        }
        return $encoded;
    }

    protected function getUserGroups(): array
    {
        $groups = [];
        $sql = "SELECT g.rowid, g.nom as name, g.note"
            . " FROM " . MAIN_DB_PREFIX . "usergroup as g"
            . " INNER JOIN " . MAIN_DB_PREFIX . "usergroup_user as gu ON g.rowid = gu.fk_usergroup"
            . " WHERE gu.fk_user = " . (int) $this->userId
            . " AND g.entity IN (0, " . (int) $this->entityId . ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $groups[] = [
                    'id' => (int) $obj->rowid,
                    'name' => $obj->name,
                ];
            }
        }
        return $groups;
    }

    protected function getUserPermissions(): array
    {
        $permissions = [];
        $sql = "SELECT DISTINCT rd.module, rd.perms, rd.subperms"
            . " FROM " . MAIN_DB_PREFIX . "user_rights as ur"
            . " INNER JOIN " . MAIN_DB_PREFIX . "rights_def as rd ON ur.fk_id = rd.id"
            . " WHERE ur.fk_user = " . (int) $this->userId
            . " AND rd.entity IN (0, " . (int) $this->entityId . ")"
            . " ORDER BY rd.module, rd.perms";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $perm = $obj->module . '.' . $obj->perms;
                if (!empty($obj->subperms)) {
                    $perm .= '.' . $obj->subperms;
                }
                $permissions[] = $perm;
            }
        }
        return $permissions;
    }

    protected function getUserExtrafields(): array
    {
        $extrafields = [];
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "user_extrafields WHERE fk_object = " . (int) $this->userId;

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $row = $this->db->fetch_array($resql);
            if ($row) {
                foreach ($row as $key => $value) {
                    if (in_array($key, ['rowid', 'tms', 'fk_object', 'import_key'])) {
                        continue;
                    }
                    if ($value !== null && $value !== '') {
                        $extrafields[$key] = $value;
                    }
                }
            }
        }
        return $extrafields;
    }

    protected function getLinkedThirdparty(): ?array
    {
        // Check if user has a linked thirdparty
        $sql = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "user WHERE rowid = " . (int) $this->userId;
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            return null;
        }
        $userRow = $this->db->fetch_object($resql);
        if (empty($userRow->fk_soc) || (int) $userRow->fk_soc <= 0) {
            return null;
        }

        $socId = (int) $userRow->fk_soc;
        $sql = "SELECT rowid, nom as name, name_alias, email, phone, town, zip, fk_pays as country_id, siret, siren, tva_intra"
            . " FROM " . MAIN_DB_PREFIX . "societe"
            . " WHERE rowid = " . $socId;

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $soc = $this->db->fetch_object($resql);
            return [
                'id' => (int) $soc->rowid,
                'name' => $soc->name,
                'name_alias' => $soc->name_alias,
                'email' => $soc->email,
                'phone' => $soc->phone,
                'town' => $soc->town,
                'siret' => $soc->siret,
            ];
        }
        return null;
    }
}
