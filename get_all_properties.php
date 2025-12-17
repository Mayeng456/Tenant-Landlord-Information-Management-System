<?php
session_start();
header('Content-Type: application/json');

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "tlims_db";

define('BASE_URL', 'http://localhost/TLIMS/'); 

try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        error_log("Database connection failed in get_all_properties.php: " . $conn->connect_error);
        throw new Exception("Connection failed");
    }

    /* -------------------------------------------------
       1. Session check
       ------------------------------------------------- */
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'tenant';

    /* -------------------------------------------------
       2. SINGLE PROPERTY MODE?
       ------------------------------------------------- */
    $property_id = $_GET['property_id'] ?? $_GET['id'] ?? null;

    if ($property_id > 0) {
        $query = "
            SELECT 
                p.property_id, p.landlord_id, p.title, p.description, p.address, p.price, p.status, 
                p.property_type, p.amenities, p.images, p.room_type_id, p.bedrooms, p.bathrooms, 
                p.created_at, p.latitude, p.longitude, 
                p.contact_name, p.contact_phone, 
                l.full_name AS landlord_name
            FROM properties p
            LEFT JOIN landlords l ON p.landlord_id = l.landlord_id
            WHERE p.property_id = ?
        ";
        
        // Security Check: Landlord can only see their own. Tenant can see any.
        if ($user_role === 'landlord') {
            $query .= " AND p.landlord_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('ii', $property_id, $user_id);
        } else {
            // NOTE: Tenant can view any single property, regardless of status, once they have the ID
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $property_id);
        }
        
        $stmt->execute();
        $res = $stmt->get_result();

        if ($row = $res->fetch_assoc()) {
            $row = formatPropertyRow($row);
            echo json_encode(['success' => true, 'property' => $row], JSON_UNESCAPED_SLASHES);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Property not found']);
        }
        $stmt->close();
        exit;
    }

    /* -------------------------------------------------
       3. LIST PROPERTIES BASED ON USER ROLE
       ------------------------------------------------- */
    $properties = [];
    
    if ($user_role === 'landlord') {
        // Landlord sees all their properties (available, rented, coming soon, maintenance)
        $sql = "
            SELECT 
                p.property_id, p.landlord_id, p.title, p.description, p.address, p.price, p.status, 
                p.property_type, p.amenities, p.images, p.room_type_id, p.bedrooms, p.bathrooms, 
                p.created_at, p.latitude, p.longitude, 
                p.contact_name, p.contact_phone, 
                l.full_name AS landlord_name
            FROM properties p
            LEFT JOIN landlords l ON p.landlord_id = l.landlord_id
            WHERE p.landlord_id = ?
            ORDER BY p.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
        $stmt->bind_param('i', $user_id);

    } 
    elseif ($user_role === 'tenant') {
        // --- TENANT QUERY (MODIFIED: sees 'available' and 'coming soon') ---
        $sql = "
            SELECT 
                p.property_id, p.landlord_id, p.title, p.description, p.address, p.price, p.status, 
                p.property_type, p.amenities, p.images, p.room_type_id, p.bedrooms, p.bathrooms, 
                p.created_at, p.latitude, p.longitude, 
                p.contact_name, p.contact_phone, 
                l.full_name AS landlord_name 
            FROM properties p
            LEFT JOIN landlords l ON p.landlord_id = l.landlord_id
            WHERE p.status IN ('available', 'coming soon') 
            ORDER BY p.created_at DESC
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
    } 
    else {
        throw new Exception("Invalid user role: " . $user_role);
    }

    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $properties[] = formatPropertyRow($row);
    }

    echo json_encode([
        'success'     => true,
        'properties'  => $properties,
        'user_role'   => $user_role
    ], JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    error_log("get_all_properties.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
} finally {
    if (isset($stmt)) $stmt->close();
    if (isset($conn)) $conn->close();
}

function formatPropertyRow($row) {
    // 1. I-map ang 'address' sa 'location'
    $row['location'] = $row['address'] ?? $row['location'] ?? null;
    
    // 2. I-process ang Images
    $raw = $row['images'] ?? $row['photos'] ?? $row['image'] ?? null;
    $photos = [];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $photos = $decoded;
        } elseif (is_string($raw) && strpos($raw, ',') !== false) {
            $photos = array_filter(array_map('trim', explode(',', $raw)));
        } elseif (is_string($raw) && trim($raw)) {
            $photos = [trim($raw)];
        }
    }
    // I-add ang full URL
    $row['photos'] = array_values(array_filter(array_map(function($p) {
        $p = trim($p);
        if (!$p) return null;
        // BASE_URL is defined as http://localhost/TLIMS/
        return preg_match('#^https?://#i', $p)
            ? $p
            : rtrim(BASE_URL, '/') . '/' . ltrim($p, '/');
    }, $photos)));

    // 3. I-process ang Amenities (siguraduhon nga array)
    $raw_amenities = $row['amenities'] ?? '';
    if (is_string($raw_amenities) && (str_starts_with($raw_amenities, '[') || str_starts_with($raw_amenities, '{'))) {
        $row['amenities'] = json_decode($raw_amenities, true) ?? [];
    } elseif (is_string($raw_amenities) && $raw_amenities) {
        $row['amenities'] = array_map('trim', explode(',', $raw_amenities));
    } elseif (is_array($raw_amenities)) {
        $row['amenities'] = $raw_amenities;
    } else {
        $row['amenities'] = [];
    }

    // 4. I-check ug limpyohon ang Coordinates (NEW)
    $row['latitude'] = (float)($row['latitude'] ?? 0);
    $row['longitude'] = (float)($row['longitude'] ?? 0);

    // 5. Kuhaon ang wala kinahanglana nga columns
    unset($row['images'], $row['image'], $row['address']);
    
    return $row;
}
?>