<?php

namespace App\Entity\Agency;

use App\Repository\MailS130Repository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MailS130Repository::class)]
class MailS130
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'mailS130s')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ContactS130 $id_contact = null;

    #[ORM\Column(length: 255)]
    private ?string $pdf_filename = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $sent_at = null;

    #[ORM\Column(length: 255)]
    private ?string $pdf_url = null;

    #[ORM\Column]
    private ?bool $is_pdf_sent = null;

    #[ORM\Column(length: 255)]
    private ?string $sender = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdContact(): ?ContactS130
    {
        return $this->id_contact;
    }

    public function setIdContact(?ContactS130 $id_contact): static
    {
        $this->id_contact = $id_contact;

        return $this;
    }

    public function getPdfFilename(): ?string
    {
        return $this->pdf_filename;
    }

    public function setPdfFilename(string $pdf_filename): static
    {
        $this->pdf_filename = $pdf_filename;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sent_at;
    }

    public function setSentAt(\DateTimeImmutable $sent_at): static
    {
        $this->sent_at = $sent_at;

        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdf_url;
    }

    public function setPdfUrl(string $pdf_url): static
    {
        $this->pdf_url = $pdf_url;

        return $this;
    }

    public function isPdfSent(): ?bool
    {
        return $this->is_pdf_sent;
    }

    public function setIsPdfSent(bool $is_pdf_sent): static
    {
        $this->is_pdf_sent = $is_pdf_sent;

        return $this;
    }

    public function getSender(): ?string
    {
        return $this->sender;
    }

    public function setSender(string $sender): static
    {
        $this->sender = $sender;

        return $this;
    }
}
