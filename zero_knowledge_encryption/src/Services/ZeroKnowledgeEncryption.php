<?php

namespace Simp\Pindrop\Modules\zero_knowledge_encryption\src\Services;

use Simp\Pindrop\Entity\User\CurrentUser;


class ZeroKnowledgeEncryption
{
    public function __construct(protected ?CurrentUser $currentUser){}

    public function getCurrentUser(): CurrentUser
    {
        return $this->currentUser;
    }

    public function setZeroKnowledgeEncryptionKey(string $key): bool
    {
        $systemKey = $_ENV['ZERO_KNOWLEDGE_ENCRYPTION_KEY'] ?? null;
        //dd($this, getAppContainer()->get('current_user'));
        $this->currentUser->setUserData([...$this->currentUser->getUserData(),
         'zero_knowledge_encryption_key' =>
          $systemKey ?
          $this->encryptValue($key, $systemKey) : $key]);
       
        return $this->currentUser->update();
    }

    public function getZeroKnowledgeEncryptionKey(): ?string
    {
        $systemKey = $_ENV['ZERO_KNOWLEDGE_ENCRYPTION_KEY'] ?? null;
        $userData = $this->currentUser->getUserData();
        if (!isset($userData['zero_knowledge_encryption_key'])) {
            return null;
        }
        $encryptedKey = $userData['zero_knowledge_encryption_key'];
        return $systemKey ? $this->decryptValue($encryptedKey, $systemKey) : $encryptedKey;
    }

    
    
    private function encryptValue(string $value, string $key): string
    {
        $cipher = "aes-256-gcm";
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);
        
        $tag = "";
        $ciphertext = openssl_encrypt($value, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        // Pack IV (12 bytes), Tag (16 bytes), and Ciphertext together
        return base64_encode($iv . $tag . $ciphertext);
    }

    private function decryptValue(string $encryptedValue, string $key): string
    {
        $cipher = "aes-256-gcm";
        $data = base64_decode($encryptedValue);
        
        $ivLength = openssl_cipher_iv_length($cipher);
        $tagLength = 16; // Standard AES-GCM tag size
        
        // Unpack the pieces from the binary string
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);
        
        $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
 
        if ($plaintext === false) {
            return $encryptedValue; // Return the original value if decryption fails
        }
        
        return $plaintext;
    }

    public function hasZeroKnowledgeEncryptionKey(): bool
    {
        $userData = $this->currentUser->getUserData();
        return isset($userData['zero_knowledge_encryption_key']);
    }

    public function removeZeroKnowledgeEncryptionKey(): bool
    {
        $userData = $this->currentUser->getUserData();
        if (isset($userData['zero_knowledge_encryption_key'])) {
            unset($userData['zero_knowledge_encryption_key']);
            $this->currentUser->setUserData($userData);
            return $this->currentUser->update();
        }
        return false;
    }

    public function isZeroKnowledgeEncryptionEnabled(): bool
    {
        return $this->hasZeroKnowledgeEncryptionKey();
    }

    public function isZeroKnowledgeEncryptionDisabled(): bool
    {
        return !$this->hasZeroKnowledgeEncryptionKey();
    }

    public function encryptDataColumnData(array $data, array $cols): array
    {
        // we dont know the structure of the data, in $data per column key,
        // if the column is simply scalar value, we encrypt it, if its an array, we encrypt each value in the array recursively,
        // if its an object, we encrypt each property in the object recursively
        foreach ($cols as $col) {
            if (isset($data[$col])) {
                if (is_array($data[$col])) {
                    $data[$col] = $this->encryptDataColumnData($data[$col], array_keys($data[$col]));
                } elseif (is_object($data[$col])) {
                    $objectVars = get_object_vars($data[$col]);
                    foreach ($objectVars as $property => $value) {
                        if (is_array($value)) {
                            $data[$col]->$property = $this->encryptDataColumnData($value, array_keys($value));
                        } elseif (is_object($value)) {
                            // Recursively encrypt object properties
                            $data[$col]->$property = $this->encryptDataColumnData(get_object_vars($value), array_keys(get_object_vars($value)));
                        } else {
                            $data[$col]->$property = $this->encryptValue((string)$value, $this->getZeroKnowledgeEncryptionKey());
                        }
                    }
                } else {
                    $data[$col] = $this->encryptValue((string)$data[$col], $this->getZeroKnowledgeEncryptionKey());
                }
            }
        }
        return $data;
    }

    public function decryptDataColumnData(array $data, array $cols): array
    {
       
        foreach ($cols as $col) {
            if (isset($data[$col])) {
                if (is_array($data[$col])) {
                    $data[$col] = $this->decryptDataColumnData($data[$col], array_keys($data[$col]));
                } elseif (is_object($data[$col])) {
                    $objectVars = get_object_vars($data[$col]);
                    foreach ($objectVars as $property => $value) {
                        if (is_array($value)) {
                            $data[$col]->$property = $this->decryptDataColumnData($value, array_keys($value));
                        } elseif (is_object($value)) {
                            // Recursively decrypt object properties
                            $data[$col]->$property = $this->decryptDataColumnData(get_object_vars($value), array_keys(get_object_vars($value)));
                        } else {
                            $data[$col]->$property = $this->decryptValue((string)$value, $this->getZeroKnowledgeEncryptionKey());
                        }
                    }
                } else {
                    $data[$col] = $this->decryptValue((string)$data[$col], $this->getZeroKnowledgeEncryptionKey());
                }
            }
        }
        return $data;
    }
}
